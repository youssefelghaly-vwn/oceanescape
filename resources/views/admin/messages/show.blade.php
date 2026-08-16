{{-- resources/views/admin/messages/show.blade.php --}}
<x-admin-layout :title="$message->name">
    <x-slot:heading>
        <a href="{{ route('admin.messages.index') }}"
           class="inline-flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-500 hover:text-ink-900">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 18l-6-6 6-6"/></svg>
            All messages
        </a>
        <h1 class="mt-1.5 font-display text-2xl font-medium">
            {{ $message->subject ?: 'Message from ' . $message->name }}
        </h1>
        <p class="mt-0.5 text-sm text-tide-600">
            <span class="font-mono">{{ $message->reference }}</span> ·
            {{ $message->created_at->format('M j, Y \a\t g:ia') }}
        </p>
    </x-slot:heading>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <div class="space-y-5">
            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Message</h2>
                <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-tide-700">{{ $message->message }}</p>
            </section>

            <a href="mailto:{{ $message->email }}?subject={{ rawurlencode('Re: ' . ($message->subject ?: 'Your enquiry') . ' [' . $message->reference . ']') }}"
               class="inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>
                </svg>
                Reply by email
            </a>
        </div>

        <div class="space-y-5">
            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Sender</h2>
                <p class="mt-3 font-display text-lg text-ink-900">{{ $message->name }}</p>
                <div class="mt-3 space-y-2 text-sm">
                    <a href="mailto:{{ $message->email }}" class="block break-all text-brand-700 hover:underline">{{ $message->email }}</a>
                    @if ($message->phone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $message->phone) }}" class="block text-brand-700 hover:underline">{{ $message->phone }}</a>
                    @endif
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <form method="POST" action="{{ route('admin.messages.update', $message) }}">
                    @csrf @method('PATCH')

                    <label class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">Status</label>
                    <select name="status" class="mt-1.5 w-full rounded-xl border-0 bg-fog-50 py-2.5 pl-3 pr-9 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">
                        @foreach (\App\Enums\ContactMessageStatus::options() as $value => $label)
                            <option value="{{ $value }}" @selected($message->status->value === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <label class="mt-4 block font-mono text-[10px] uppercase tracking-wide text-tide-500">Internal notes</label>
                    <textarea name="internal_notes" rows="5"
                              class="mt-1.5 w-full rounded-xl border-0 bg-fog-50 p-3 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">{{ old('internal_notes', $message->internal_notes) }}</textarea>
                    <p class="mt-1 text-[10px] text-tide-400">Never shown to the sender.</p>

                    <button class="mt-4 w-full rounded-full bg-brand-600 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
                </form>
            </section>

            <form method="POST" action="{{ route('admin.messages.destroy', $message) }}"
                  onsubmit="return confirm('Archive {{ $message->reference }}?')">
                @csrf @method('DELETE')
                <button class="w-full rounded-full px-4 py-2.5 text-sm text-tide-500 transition hover:bg-white hover:text-red-700">
                    Archive message
                </button>
            </form>
        </div>
    </div>
</x-admin-layout>
