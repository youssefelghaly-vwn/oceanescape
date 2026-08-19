<?php

namespace App\Http\Controllers;

use App\Services\Google\GoogleReviewsService;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(protected GoogleReviewsService $google) {}

    /** GET /reviews */
    public function index(): View
    {
        return view('pages.reviews', [
            'data' => $this->google->fetch(),
        ]);
    }
}
