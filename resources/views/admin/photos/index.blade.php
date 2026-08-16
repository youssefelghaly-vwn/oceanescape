{{-- resources/views/admin/photos/index.blade.php --}}
<x-admin-layout title="Guest photos">
    <x-slot:heading>
        <h1 class="font-display text-2xl font-medium">Guest photos</h1>
        <p class="mt-0.5 text-sm text-tide-600">
            {{ $counts['pending'] }} awaiting review · {{ $counts['approved'] }} published
        </p>
    </x-slot:heading>

    {{-- status tabs --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        @foreach ([
            'pending'  => 'Awaiting review',
            'approved' => 'Published',
            'rejected' => 'Rejected',
            'all'      => 'Everything',
        ] as $value => $label)
            <a href="{{ route('admin.photos.index', ['status' => $value]) }}"
               class="rounded-full px-4 py-2 font-mono text-[10px] uppercase tracking-wide transition
                      {{ ($filters['status'] ?? 'pending') === $value ? 'bg-ink-900 text-white' : 'bg-white text-tide-600 ring-1 ring-fog-300 hover:ring-brand-300' }}">
                {{ $label }}
                <span class="opacity-60">{{ $counts[$value] ?? $counts['all'] }}</span>
            </a>
        @endforeach
    </div>

    @if ($photos->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-black/5">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-fog-100 text-tide-400">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                </svg>
            </div>
            <p class="mt-4 font-display text-lg text-ink-900">Nothing in this queue</p>
            <p class="mt-1 text-sm text-tide-600">Photos guests upload will appear here for review.</p>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($photos as $photo)
                <article class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">

                    {{-- Pending images stream through an authenticated route;
                         only approved ones have a public URL. --}}
                    <a href="{{ $photo->status === \App\Enums\GuestPhotoStatus::Approved ? $photo->url : $photo->preview_url }}"
                       target="_blank" rel="noopener"
                       class="block aspect-[4/3] overflow-hidden bg-fog-100">
                        <img src="{{ $photo->status === \App\Enums\GuestPhotoStatus::Approved ? $photo->url : $photo->preview_url }}"
                             alt="{{ $photo->caption ?: $photo->original_name }}"
                             loading="lazy" class="h-full w-full object-cover">
                    </a>

                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink-900">
                                    {{ $photo->caption ?: 'No caption' }}
                                </p>
                                <p class="mt-0.5 truncate text-xs text-tide-500">
                                    {{ $photo->guest_name }} · {{ $photo->created_at->diffForHumans(short: true) }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide ring-1 {{ $photo->status->classes() }}">
                                {{ $photo->status->label() }}
                            </span>
                        </div>

                        <p class="mt-2 font-mono text-[10px] text-tide-400">
                            {{ $photo->size_label }}@if ($photo->dimensions_label) · {{ $photo->dimensions_label }}@endif
                            @if ($photo->cottage_name) · {{ Str::limit($photo->cottage_name, 22) }} @endif
                        </p>

                        @if ($photo->rejection_reason)
                            <p class="mt-2 rounded-xl bg-fog-50 px-3 py-2 text-xs text-tide-600">
                                {{ $photo->rejection_reason }}
                            </p>
                        @endif

                        {{-- approve --}}
                        <form method="POST" action="{{ route('admin.photos.approve', $photo) }}" class="mt-4">
                            @csrf @method('PATCH')

                            <input name="caption" value="{{ $photo->caption }}" maxlength="300"
                                   placeholder="Caption shown in the gallery"
                                   class="w-full rounded-xl border-0 bg-fog-50 px-3 py-2 text-xs ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400">

                            <label class="mt-2 flex items-center gap-2 text-xs text-tide-600">
                                <input type="checkbox" name="is_featured" value="1" @checked($photo->is_featured)
                                       class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
                                Feature at the top of the gallery
                            </label>

                            <button class="mt-3 w-full rounded-full bg-emerald-600 py-2.5 text-xs font-semibold uppercase tracking-wide text-white transition hover:bg-emerald-700">
                                {{ $photo->status === \App\Enums\GuestPhotoStatus::Approved ? 'Save changes' : 'Approve & publish' }}
                            </button>
                        </form>

                        {{-- reject --}}
                        <form method="POST" action="{{ route('admin.photos.reject', $photo) }}" class="mt-2">
                            @csrf @method('PATCH')
                            <div class="flex gap-2">
                                <input name="rejection_reason" maxlength="200" placeholder="Reason (optional, internal)"
                                       class="min-w-0 flex-1 rounded-xl border-0 bg-fog-50 px-3 py-2 text-xs ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400">
                                <button class="shrink-0 rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-wide text-tide-600 ring-1 ring-fog-300 transition hover:bg-fog-50 hover:text-ink-900">
                                    Reject
                                </button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.photos.destroy', $photo) }}" class="mt-2"
                              onsubmit="return confirm('Delete this photo and its file permanently? This cannot be undone.')">
                            @csrf @method('DELETE')
                            <button class="w-full py-1.5 text-[11px] text-tide-400 transition hover:text-red-700">
                                Delete permanently
                            </button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-6">{{ $photos->links() }}</div>
    @endif
</x-admin-layout>
