<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContactMessageStatus;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class ContactMessageController extends Controller
{
    /** GET /admin/messages */
    public function index(Request $request): View
    {
        $messages = ContactMessage::query()
            ->search($request->query('q'))
            ->status($request->query('status'))
            ->when($request->query('view') === 'unhandled', fn ($q) => $q->unhandled())
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.messages.index', [
            'messages' => $messages,
            'counts'   => $this->counts(),
            'filters'  => $request->only(['q', 'status', 'view']),
        ]);
    }

    /** GET /admin/messages/{contactMessage} */
    public function show(ContactMessage $contactMessage): View
    {
        // Opening it is what "read" means — no extra click required.
        $contactMessage->markRead();

        return view('admin.messages.show', [
            'message' => $contactMessage->load('handler'),
        ]);
    }

    /** PATCH /admin/messages/{contactMessage} */
    public function update(Request $request, ContactMessage $contactMessage): RedirectResponse
    {
        $data = $request->validate([
            'status'         => ['required', 'in:' . implode(',', array_keys(ContactMessageStatus::options()))],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = ContactMessageStatus::from($data['status']);
        $extra  = [];

        if ($status === ContactMessageStatus::Replied && !$contactMessage->replied_at) {
            $extra['replied_at'] = now();
        }

        $contactMessage->update($data + $extra + [
            'handled_by' => $contactMessage->handled_by ?? $request->user()?->id,
        ]);

        return back()->with('status', "Updated {$contactMessage->reference}.");
    }

    /** DELETE /admin/messages/{contactMessage} */
    public function destroy(ContactMessage $contactMessage): RedirectResponse
    {
        $ref = $contactMessage->reference;
        $contactMessage->delete();

        return redirect()->route('admin.messages.index')->with('status', "Archived {$ref}.");
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        $counts = ContactMessage::selectRaw('status, count(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status')->all();

        $out = ['all' => array_sum($counts)];
        foreach (ContactMessageStatus::cases() as $case) {
            $out[$case->value] = (int) ($counts[$case->value] ?? 0);
        }
        return $out;
    }
}
