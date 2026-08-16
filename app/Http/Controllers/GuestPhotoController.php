<?php

namespace App\Http\Controllers;

use App\Enums\GuestPhotoStatus;
use App\Http\Requests\StoreGuestPhotoRequest;
use App\Models\GuestPhoto;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GuestPhotoController extends Controller
{
    public function __construct(protected LodgifyRepository $lodgify) {}

    /** GET /share-your-photos */
    public function create(): View
    {
        $cottages = collect();
        try {
            $cottages = $this->lodgify->allCottages();
        } catch (\Throwable $e) {
            Log::warning('Photo upload page: cottage list unavailable', ['message' => $e->getMessage()]);
        }

        return view('pages.photo-upload', ['cottages' => $cottages]);
    }

    /** POST /share-your-photos */
    public function store(StoreGuestPhotoRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $cottageName = null;
        if (!empty($data['cottage_id'])) {
            try {
                $cottageName = $this->lodgify->cottage((int) $data['cottage_id'])?->name;
            } catch (\Throwable) {
                // a missing name is cosmetic; carry on
            }
        }

        $saved = 0;

        foreach ($request->file('photos', []) as $file) {
            /*
             * Stored on the PRIVATE disk under a random name.
             *
             * Two reasons for private: nothing unmoderated is ever reachable by
             * URL, and the file is only promoted to the public disk when a
             * human approves it. The random name means the original filename
             * (often a guest's device path) never appears in a URL.
             */
            $path = $file->store('guest-photos/pending', 'local');

            [$width, $height] = $this->dimensions($file->getRealPath());

            GuestPhoto::create([
                'guest_name'    => $data['guest_name'],
                'guest_email'   => $data['guest_email'],
                'caption'       => $data['caption'] ?? null,
                'cottage_id'    => $data['cottage_id'] ?? null,
                'cottage_name'  => $cottageName,
                'stayed_on'     => $data['stayed_on'] ?? null,
                'disk'          => 'local',
                'path'          => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 190, ''),
                'mime'          => $file->getMimeType(),
                'size_bytes'    => $file->getSize(),
                'width'         => $width,
                'height'        => $height,
                'status'        => GuestPhotoStatus::Pending,
                'consent_given' => true,
                'ip_address'    => $request->ip(),
                'user_agent'    => substr((string) $request->userAgent(), 0, 512),
            ]);

            $saved++;
        }

        return back()->with('photos_uploaded', $saved);
    }

    /**
     * Pixel dimensions, best-effort.
     *
     * getimagesize() also acts as a second content check: a file that is not a
     * real image returns false here even if it slipped past validation.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function dimensions(string $path): array
    {
        try {
            $info = @getimagesize($path);
            return is_array($info) ? [$info[0] ?? null, $info[1] ?? null] : [null, null];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
