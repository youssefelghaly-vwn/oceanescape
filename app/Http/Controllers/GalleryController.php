<?php

namespace App\Http\Controllers;

use App\Models\GuestPhoto;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class GalleryController extends Controller
{
    /** GET /gallery */
    public function index(Request $request): View
    {
        $photos = GuestPhoto::approved()
            ->when($request->integer('cottage'), fn ($q, $id) => $q->where('cottage_id', $id))
            ->galleryOrder()
            ->paginate(24)
            ->withQueryString();

        // Only cottages that actually have published photos, so the filter
        // never offers a choice that returns nothing.
        $cottages = GuestPhoto::approved()
            ->whereNotNull('cottage_id')
            ->selectRaw('cottage_id, cottage_name, count(*) as total')
            ->groupBy('cottage_id', 'cottage_name')
            ->orderByDesc('total')
            ->get();

        return view('pages.gallery', [
            'photos'   => $photos,
            'cottages' => $cottages,
            'active'   => $request->integer('cottage'),
        ]);
    }
}
