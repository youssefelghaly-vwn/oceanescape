<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GuestPhotoStatus;
use App\Models\GuestPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestPhotoController extends Controller
{
    /** GET /admin/photos */
    public function index(Request $request): View
    {
        // Default to the queue that needs attention rather than everything.
        $status = $request->query('status', GuestPhotoStatus::Pending->value);

        $photos = GuestPhoto::query()
            ->status($status === 'all' ? null : $status)
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($q) => $q->where('guest_name', 'like', "%{$term}%")
                             ->orWhere('guest_email', 'like', "%{$term}%")
                             ->orWhere('caption', 'like', "%{$term}%")
            ))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        return view('admin.photos.index', [
            'photos'  => $photos,
            'counts'  => $this->counts(),
            'filters' => ['status' => $status, 'q' => $request->query('q')],
        ]);
    }

    /**
     * GET /admin/photos/{guestPhoto}/file
     *
     * Streams a pending image from the private disk. Pending uploads are never
     * on the public disk, so this authenticated route is the only way to view
     * one — which is the point.
     */
    public function file(GuestPhoto $guestPhoto): StreamedResponse
    {
        abort_unless(Storage::disk($guestPhoto->disk)->exists($guestPhoto->path), 404);

        return Storage::disk($guestPhoto->disk)->response(
            $guestPhoto->path,
            $guestPhoto->original_name,
            [
                'Content-Type'  => $guestPhoto->mime,
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    /**
     * PATCH /admin/photos/{guestPhoto}/approve
     *
     * Promotes the file from the private disk to the public one. The record is
     * only marked approved after the copy succeeds, so a storage failure can
     * never leave a published photo pointing at a file that is not there.
     */
    public function approve(Request $request, GuestPhoto $guestPhoto): RedirectResponse
    {
        $data = $request->validate([
            'caption'     => ['nullable', 'string', 'max:300'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        if ($guestPhoto->status !== GuestPhotoStatus::Approved) {
            try {
                $publicPath = 'guest-photos/' . basename($guestPhoto->path);

                Storage::disk('public')->put(
                    $publicPath,
                    Storage::disk($guestPhoto->disk)->get($guestPhoto->path)
                );

                $oldDisk = $guestPhoto->disk;
                $oldPath = $guestPhoto->path;

                $guestPhoto->update([
                    'disk'             => 'public',
                    'path'             => $publicPath,
                    'status'           => GuestPhotoStatus::Approved,
                    'rejection_reason' => null,
                    'reviewed_by'      => $request->user()?->id,
                    'reviewed_at'      => now(),
                    'caption'          => $data['caption'] ?? $guestPhoto->caption,
                    'is_featured'      => $request->boolean('is_featured'),
                ]);

                // Only remove the private original once the record points at
                // the new location.
                if ($oldDisk === 'local') {
                    Storage::disk('local')->delete($oldPath);
                }
            } catch (\Throwable $e) {
                Log::error('Photo approval failed', [
                    'photo'   => $guestPhoto->id,
                    'message' => $e->getMessage(),
                ]);
                return back()->withErrors(['photo' => 'Could not publish that photo. Please try again.']);
            }
        } else {
            $guestPhoto->update([
                'caption'     => $data['caption'] ?? $guestPhoto->caption,
                'is_featured' => $request->boolean('is_featured'),
            ]);
        }

        return back()->with('status', 'Photo published.');
    }

    /**
     * PATCH /admin/photos/{guestPhoto}/reject
     *
     * The file stays on the private disk: a rejection may need revisiting, and
     * the record is evidence of what was submitted.
     */
    public function reject(Request $request, GuestPhoto $guestPhoto): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:200'],
        ]);

        // If it had already been published, pull it back off the public disk.
        if ($guestPhoto->disk === 'public') {
            try {
                $privatePath = 'guest-photos/pending/' . basename($guestPhoto->path);
                Storage::disk('local')->put($privatePath, Storage::disk('public')->get($guestPhoto->path));
                Storage::disk('public')->delete($guestPhoto->path);
                $guestPhoto->disk = 'local';
                $guestPhoto->path = $privatePath;
            } catch (\Throwable $e) {
                Log::error('Photo unpublish failed', ['photo' => $guestPhoto->id, 'message' => $e->getMessage()]);
            }
        }

        $guestPhoto->forceFill([
            'status'           => GuestPhotoStatus::Rejected,
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'reviewed_by'      => $request->user()?->id,
            'reviewed_at'      => now(),
            'is_featured'      => false,
        ])->save();

        return back()->with('status', 'Photo rejected and taken off the site.');
    }

    /** DELETE /admin/photos/{guestPhoto} — removes the record and the file. */
    public function destroy(GuestPhoto $guestPhoto): RedirectResponse
    {
        $guestPhoto->forceDelete();   // model event deletes the file too

        return back()->with('status', 'Photo deleted permanently.');
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        $counts = GuestPhoto::selectRaw('status, count(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status')->all();

        $out = ['all' => array_sum($counts)];
        foreach (GuestPhotoStatus::cases() as $case) {
            $out[$case->value] = (int) ($counts[$case->value] ?? 0);
        }
        return $out;
    }
}
