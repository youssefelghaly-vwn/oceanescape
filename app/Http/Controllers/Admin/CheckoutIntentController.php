<?php

namespace App\Http\Controllers\Admin;

use App\Models\CheckoutIntent;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class CheckoutIntentController extends Controller
{
    /** GET /admin/checkouts */
    public function index(Request $request): View
    {
        $status = $request->query('status', 'all');

        $intents = CheckoutIntent::query()
            ->status($status)
            ->latest()
            ->paginate(30)
            ->withQueryString();

        /*
         * Anything older than the grace period that never converted is treated
         * as abandoned for reporting. Left as-is in the database rather than
         * rewritten, so a late webhook can still claim it.
         */
        $grace     = (int) config('lodgify.checkout_grace_minutes', 90);
        $total     = CheckoutIntent::count();
        $converted = CheckoutIntent::converted()->count();
        $stale     = CheckoutIntent::stale($grace)->count();

        return view('admin.checkouts.index', [
            'intents' => $intents,
            'status'  => $status,
            'stats'   => [
                'total'      => $total,
                'converted'  => $converted,
                'abandoned'  => $stale,
                'in_flight'  => max(0, $total - $converted - $stale),
                'rate'       => $total > 0 ? round(($converted / $total) * 100, 1) : null,
            ],
        ]);
    }
}
