<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class WebsiteLayout extends Component
{
    /**
     * @param  string  $title  Page <title>. Override per-page.
     * @param  string  $description  Meta description. Override per-page.
     * @param  bool  $transparentNav  True on pages with a full-bleed hero
     *                                (nav starts transparent, solidifies on
     *                                scroll). False everywhere else.
     */
    public function __construct(
        public string $title = 'Ocean Escape Cottages | Oceanfront Cottage Rentals in Nova Scotia',
        public string $description = 'Six oceanfront cottages on the Nova Scotia coast. Real-time availability and transparent pricing — see the total before you book.',
        public bool $transparentNav = false,
    ) {}

    public function render(): View
    {
        return view('layouts.website');
    }
}