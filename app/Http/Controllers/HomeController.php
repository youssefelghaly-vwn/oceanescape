<?php

namespace App\Http\Controllers;

use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(protected LodgifyRepository $lodgify) {}

    public function index(): View
    {
        $listings = collect();

        try {
            $listings = $this->lodgify->cottagesWithOpenings(windowsPerCottage: 2);
        } catch (\Throwable $e) {
            Log::error('home.index failed', ['message' => $e->getMessage()]);
            try {
                $listings = $this->lodgify->allCottages()
                    ->map(fn ($c) => ['cottage' => $c, 'windows' => []]);
            } catch (\Throwable) {
                $listings = collect();
            }
        }

        return view('pages.home', ['listings' => $listings]);
    }
}