{{-- resources/views/admin/messages/index.blade.php --}}
<x-admin-layout title="Messages">
    <x-slot:heading>
        <h1 class="font-display text-2xl font-medium">Contact messages</h1>
        <p class="mt-0.5 text-sm text-tide-600">
            {{ $counts['all'] }} total · {{ $counts['new'] }} unread
        </p>
    </x-slot:heading>

    <form method="GET" class="mb-5 flex flex-wrap items-center gap-2">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email, subject…"
               class="min-w-0 flex-1 rounded-full border-0 bg-white px-4 py-2.5 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400 sm:max-w-xs">

        <select name="status" class="rounded-full border-0 bg-white py-2.5 pl-4 pr-9 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">
            <option value="">All statuses</option>
            @foreach (\App\Enums\ContactMessageStatus::options() as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>
                    {{ $label }} ({{ $counts[$value] ?? 0 }})
                </option>
            @endforeach
        </select>

        <label class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm ring-1 ring-fog-300">
            <input type="checkbox" name="view" value="unhandled" @checked(($filters['view'] ?? '') === 'unhandled')
                   class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
            Needs a reply
        </label>

        <button class="rounded-full bg-ink-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-ink-800">Filter</button>
    </form>

    @if ($messages->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-black/5">
            <p class="font-display text-lg text-ink-900">No messages here</p>
            <p class="mt-1 text-sm text-tide-600">Enquiries from the contact form will appear in this list.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-fog-200 bg-fog-50">
                    <tr class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                        <th class="px-5 py-3 font-medium">From</th>
                        <th class="px-5 py-3 font-medium">Message</th>
                        <th class="px-5 py-3 font-medium">Received</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-fog-200">
                    @foreach ($messages as $message)
                        <tr class="cursor-pointer transition hover:bg-fog-50"
                            onclick="window.location='{{ route('admin.messages.show', $message) }}'">
                            <td class="px-5 py-4 align-top">
                                <span class="block font-medium {{ $message->status === \App\Enums\ContactMessageStatus::New ? 'text-ink-900' : 'text-tide-700' }}">
                                    {{ $message->name }}
                                </span>
                                <span class="mt-0.5 block text-xs text-tide-500">{{ $message->email }}</span>
                            </td>
                            <td class="px-5 py-4 align-top">
                                @if ($message->subject)
                                    <span class="block font-medium text-ink-900">{{ $message->subject }}</span>
                                @endif
                                <span class="mt-0.5 block text-xs text-tide-500">{{ $message->excerpt }}</span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 align-top text-tide-600">
                                {{ $message->created_at->diffForHumans(short: true) }}
                            </td>
                            <td class="px-5 py-4 align-top">
                                <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $message->status->classes() }}">
                                    {{ $message->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $messages->links() }}</div>
    @endif
</x-admin-layout>
