<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use League\CommonMark\CommonMarkConverter;
use Illuminate\Routing\Controller;
class DocsController extends Controller
{
    private string $docsPath;

    public function __construct()
    {
        $this->docsPath = base_path('Docs');
    }

    public function index()
    {
        $documents = $this->getDocuments();

        if (empty($documents)) {
            abort(404, 'No documentation found.');
        }

        return redirect()->route('docs.show', [
            'doc' => $documents[0]['slug'],
        ]);
    }

    public function show(string $doc)
    {
        $documents = $this->getDocuments();

        $document = collect($documents)
            ->firstWhere('slug', $doc);

        abort_unless($document, 404);

        $markdown = File::get($document['path']);

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = $converter->convert($markdown)->getContent();

        return view('docs.show', [
            'document' => $document,
            'documents' => $documents,
            'content' => $html,
        ]);
    }

    private function getDocuments(): array
    {
        return collect(File::files($this->docsPath))
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->map(function ($file) {
                $filename = $file->getFilenameWithoutExtension();

                // Remove ordering prefix: 01-, 02-, etc.
                $slug = preg_replace('/^\d+-/', '', $filename);

                // README becomes overview
                if (strtolower($slug) === 'readme') {
                    $slug = 'overview';
                }

                return [
                    'filename' => $file->getFilename(),
                    'name' => $this->formatName($filename),
                    'slug' => $slug,
                    'path' => $file->getPathname(),
                ];
            })
            ->sortBy(function ($document) {
                return $document['filename'];
            })
            ->values()
            ->all();
    }

    private function formatName(string $filename): string
    {
        $filename = preg_replace('/^\d+-/', '', $filename);

        if (strtolower($filename) === 'readme') {
            return 'Overview';
        }

        return ucwords(
            str_replace('-', ' ', $filename)
        );
    }
}