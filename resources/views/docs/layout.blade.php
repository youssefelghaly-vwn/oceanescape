<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        @yield('title', 'Documentation')
    </title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .docs-content h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .docs-content h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-top: 3rem;
            margin-bottom: 1rem;
        }

        .docs-content h3 {
            font-size: 1.35rem;
            font-weight: 600;
            margin-top: 2rem;
            margin-bottom: .75rem;
        }

        .docs-content p {
            margin: 1rem 0;
            line-height: 1.8;
        }

        .docs-content ul {
            list-style: disc;
            margin: 1rem 0;
            padding-left: 1.5rem;
        }

        .docs-content ol {
            list-style: decimal;
            margin: 1rem 0;
            padding-left: 1.5rem;
        }

        .docs-content li {
            margin: .35rem 0;
        }

        .docs-content a {
            text-decoration: underline;
        }

        .docs-content blockquote {
            border-left: 4px solid #d1d5db;
            padding-left: 1rem;
            margin: 1.5rem 0;
            color: #6b7280;
        }

        .docs-content code {
            background: #f3f4f6;
            padding: .15rem .35rem;
            border-radius: .25rem;
            font-size: .9em;
        }

        .docs-content pre {
            background: #111827;
            color: #f9fafb;
            padding: 1.25rem;
            border-radius: .75rem;
            overflow-x: auto;
            margin: 1.5rem 0;
        }

        .docs-content pre code {
            background: transparent;
            padding: 0;
        }

        .docs-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
        }

        .docs-content th,
        .docs-content td {
            border: 1px solid #e5e7eb;
            padding: .75rem;
            text-align: left;
        }

        .docs-content th {
            background: #f9fafb;
            font-weight: 600;
        }
    </style>
</head>

<body class="bg-white text-gray-900">

<div class="min-h-screen">

    {{-- Mobile header --}}
    <header class="border-b lg:hidden">
        <div class="flex items-center justify-between px-5 py-4">
            <a
                href="{{ route('docs.index') }}"
                class="font-bold text-lg"
            >
                Documentation
            </a>

            <button
                onclick="document
                    .getElementById('mobile-sidebar')
                    .classList.toggle('hidden')"
                class="rounded-lg border px-3 py-2"
            >
                Menu
            </button>
        </div>
    </header>

    <div class="flex">

        {{-- Sidebar --}}
        <aside
            id="mobile-sidebar"
            class="
                hidden lg:block
                fixed lg:sticky
                top-0
                left-0
                h-screen
                w-72
                shrink-0
                overflow-y-auto
                border-r
                bg-gray-50
            "
        >
            <div class="p-6">

                <a
                    href="{{ route('docs.index') }}"
                    class="block mb-8 text-xl font-bold"
                >
                    Documentation
                </a>

                <nav class="space-y-1">

                    @foreach($documents as $item)

                        <a
                            href="{{ route('docs.show', $item['slug']) }}"
                            class="
                                block
                                rounded-lg
                                px-3
                                py-2
                                text-sm
                                transition
                                {{ $item['slug'] === $document['slug']
                                    ? 'bg-gray-900 text-white'
                                    : 'text-gray-700 hover:bg-gray-200'
                                }}
                            "
                        >
                            {{ $item['name'] }}
                        </a>

                    @endforeach

                </nav>

            </div>
        </aside>

        {{-- Main content --}}
        <main class="min-w-0 flex-1">

            <div class="mx-auto max-w-4xl px-6 py-10 lg:px-12">

                {{-- Breadcrumb --}}
                <div class="mb-8 text-sm text-gray-500">
                    Documentation
                    <span class="mx-2">/</span>
                    {{ $document['name'] }}
                </div>

                <article class="docs-content">
                    {!! $content !!}
                </article>

            </div>

        </main>

    </div>

</div>

</body>
</html>