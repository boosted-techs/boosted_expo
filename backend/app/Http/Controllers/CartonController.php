<?php

namespace App\Http\Controllers;

use App\Models\Carton;
use App\Models\LabelTemplate;
use App\Models\QrIdentity;
use App\Models\StockBatch;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CartonController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission
        ), 403);
    }

    private function own(Request $request, int $id): Carton
    {
        return Carton::query()->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'cartons.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $search = trim((string) $request->query('search', ''));
        $cartons = Carton::query()->where('business_id', $businessId)
            ->with('packingJob.product')
            ->when($search !== '', fn ($q) => $q->where('carton_code', 'like', '%'.$search.'%'))
            ->orderByDesc('id')->workspacePaginate(25)->withQueryString();

        return view('cartons.index', compact('cartons', 'search'));
    }

    public function show(Request $request, int $carton): View
    {
        $this->check($request, 'cartons.view');
        $carton = $this->own($request, $carton)->load([
            'packingJob.product', 'contents.batch', 'shipmentAllocation.shipment',
        ]);
        $qr = QrIdentity::firstOrCreate(
            ['business_id' => $carton->business_id, 'level' => 'carton', 'record_id' => $carton->id],
            ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
        );
        $templates = LabelTemplate::query()->where('business_id', $carton->business_id)
            ->where('active', true)->orderBy('name')->get();
        $canPublish = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'traceability.publish'
        );
        $canPrint = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'labels.generate'
        );

        return view('cartons.show', compact('carton', 'qr', 'templates', 'canPublish', 'canPrint'));
    }

    public function visibility(Request $request, int $carton): RedirectResponse
    {
        $this->check($request, 'traceability.publish');
        $carton = $this->own($request, $carton);
        $data = $request->validate(['public_enabled' => ['required', 'boolean']]);
        QrIdentity::firstOrCreate(
            ['business_id' => $carton->business_id, 'level' => 'carton', 'record_id' => $carton->id],
            ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
        )->update(['public_enabled' => (bool) $data['public_enabled']]);

        return back()->with('status', $data['public_enabled']
            ? 'Public carton information enabled.'
            : 'Public carton information hidden.');
    }

    public function batchVisibility(Request $request, int $batch): RedirectResponse
    {
        $this->check($request, 'traceability.publish');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $batch = StockBatch::query()->where('business_id', $businessId)->findOrFail($batch);
        $data = $request->validate(['public_enabled' => ['required', 'boolean']]);
        QrIdentity::firstOrCreate(
            ['business_id' => $businessId, 'level' => 'batch', 'record_id' => $batch->id],
            ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
        )->update(['public_enabled' => (bool) $data['public_enabled']]);

        return back()->with('status', 'Batch QR page visibility updated.');
    }
}
