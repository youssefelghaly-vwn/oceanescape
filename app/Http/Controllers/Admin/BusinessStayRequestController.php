<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BusinessStayStatus;
use App\Models\BusinessStayRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class BusinessStayRequestController extends Controller
{
    /** GET /admin/business-stays */
    public function index(Request $request): View
    {
        $sort = in_array($request->query('sort'), ['created_at', 'check_in', 'guests_count', 'company_name'], true)
            ? $request->query('sort') : 'created_at';
        $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $requests = BusinessStayRequest::query()
            ->search($request->query('q'))
            ->status($request->query('status'))
            ->when($request->query('view') === 'open', fn ($q) => $q->open())
            ->orderBy($sort, $dir)
            ->paginate(20)
            ->withQueryString();

        return view('admin.business-stays.index', [
            'requests' => $requests,
            'counts'   => $this->statusCounts(),
            'filters'  => [
                'q'      => $request->query('q'),
                'status' => $request->query('status'),
                'view'   => $request->query('view'),
                'sort'   => $sort,
                'dir'    => $dir,
            ],
        ]);
    }

    /** GET /admin/business-stays/{businessStayRequest} */
    public function show(BusinessStayRequest $businessStayRequest): View
    {
        return view('admin.business-stays.show', [
            'stay' => $businessStayRequest->load('handler'),
        ]);
    }

    /** PATCH /admin/business-stays/{businessStayRequest} */
    public function update(Request $request, BusinessStayRequest $businessStayRequest): RedirectResponse
    {
        $data = $request->validate([
            'status'         => ['required', 'string', 'in:' . implode(',', array_keys(BusinessStayStatus::options()))],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = BusinessStayStatus::from($data['status']);

        // Stamp the milestone the first time each status is reached, so the
        // timeline reflects what actually happened rather than the last edit.
        $timestamps = [];
        if ($status === BusinessStayStatus::Contacted && !$businessStayRequest->contacted_at) {
            $timestamps['contacted_at'] = now();
        }
        if ($status === BusinessStayStatus::Quoted && !$businessStayRequest->quoted_at) {
            $timestamps['quoted_at'] = now();
        }
        if (!$status->isOpen() && !$businessStayRequest->closed_at) {
            $timestamps['closed_at'] = now();
        }

        $businessStayRequest->update($data + $timestamps + [
            'handled_by' => $businessStayRequest->handled_by ?? $request->user()?->id,
        ]);

        return back()->with('status', "Updated {$businessStayRequest->reference}.");
    }

    /** DELETE /admin/business-stays/{businessStayRequest} */
    public function destroy(BusinessStayRequest $businessStayRequest): RedirectResponse
    {
        $reference = $businessStayRequest->reference;
        $businessStayRequest->delete();   // soft delete — enquiries are evidence

        return redirect()
            ->route('admin.business-stays.index')
            ->with('status', "Archived {$reference}.");
    }

    /** @return array<string, int> */
    protected function statusCounts(): array
    {
        $counts = BusinessStayRequest::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $out = ['all' => array_sum($counts)];
        foreach (BusinessStayStatus::cases() as $case) {
            $out[$case->value] = (int) ($counts[$case->value] ?? 0);
        }
        return $out;
    }
}