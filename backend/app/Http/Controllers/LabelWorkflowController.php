<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Carton;
use App\Models\ExportShipment;
use App\Models\LabelEvent;
use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelPrintAgent;
use App\Models\LabelPrintBatch;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\QrIdentity;
use App\Models\SalesOrder;
use App\Models\StockBatch;
use App\Services\LabelPrintQueue;
use App\Services\LabelWorkflow;
use App\Support\BusinessPermissions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LabelWorkflowController extends Controller
{
    public function helper(Request $request): BinaryFileResponse
    {
        $this->business($request, 'labels.manage');

        return response()->download(resource_path('printing/zebra_agent.py'), 'zebra_agent.py', ['Cache-Control' => 'private, no-store']);
    }

    private function business(Request $request, string $permission = 'labels.generate'): Business
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);

        return $request->attributes->get('activeBusiness');
    }

    public function profiles(Request $request): View
    {
        $business = $this->business($request, 'labels.view');
        $profiles = LabelMediaProfile::query()->where('business_id', $business->id)->orderBy('name')->get();
        $canManage = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'labels.manage');

        return view('labels.profiles', compact('business', 'profiles', 'canManage'));
    }

    public function profile(Request $request): RedirectResponse
    {
        $business = $this->business($request, 'labels.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100', Rule::unique('label_media_profiles')->where('business_id', $business->id)],
            'printer_model' => ['required', 'string', 'max:100'], 'protocol' => ['required', Rule::in(['zpl', 'pdf'])],
            'dpi' => ['required', Rule::in([203, 300])],
            'width_mm' => ['required', 'numeric', 'decimal:0,2', 'between:25,150'], 'height_mm' => ['required', 'numeric', 'decimal:0,2', 'between:15,200'],
            'margin_mm' => ['required', 'numeric', 'decimal:0,2', 'between:1,10'],
            'offset_x_mm' => ['required', 'numeric', 'decimal:0,2', 'between:-5,5'], 'offset_y_mm' => ['required', 'numeric', 'decimal:0,2', 'between:-5,5'],
            'media_type' => ['required', Rule::in(['gap', 'black_mark', 'continuous'])], 'orientation' => ['required', Rule::in([0, 180])],
        ]);
        if (preg_match('/GK\s*888\s*T/i', $data['printer_model']) && ((int) $data['dpi'] !== 203 || (float) $data['width_mm'] > 104)) {
            throw ValidationException::withMessages(['printer_model' => 'GK888t uses 203 dpi and a maximum printable width of 104 mm. Choose those settings or specify your actual different printer model.']);
        }
        if ((float) $data['margin_mm'] + abs((float) $data['offset_x_mm']) >= (float) $data['width_mm'] / 2 || (float) $data['margin_mm'] + abs((float) $data['offset_y_mm']) >= (float) $data['height_mm'] / 2 || abs((float) $data['offset_x_mm']) > (float) $data['margin_mm'] || abs((float) $data['offset_y_mm']) > (float) $data['margin_mm']) {
            throw ValidationException::withMessages(['margin_mm' => 'Margins and offsets must keep all content inside the media.']);
        }
        LabelMediaProfile::create([...$data, 'business_id' => $business->id, 'created_by' => $request->user()->id]);

        return back()->with('status', 'Printer and media profile saved. Print and scan a test sticker before marking this setup tested.');
    }

    public function testProfile(Request $request, int $profile): RedirectResponse
    {
        $business = $this->business($request, 'labels.manage');
        $data = $request->validate(['test_evidence' => ['required', 'string', 'min:20', 'max:1500'], 'confirmed_scan' => ['required', 'accepted']]);
        $profile = LabelMediaProfile::query()->where('business_id', $business->id)->findOrFail($profile);
        $profile->update(['test_evidence' => $data['test_evidence'], 'tested_by' => $request->user()->id, 'tested_at' => now()]);

        return back()->with('status', 'Your physical print and scan test evidence was recorded for this profile.');
    }

    public function policy(Request $request): RedirectResponse
    {
        $business = $this->business($request, 'labels.manage');
        abort_unless($request->attributes->get('membership')->role === 'owner', 403);
        $data = $request->validate(['label_verification_required' => ['required', 'boolean']]);
        DB::transaction(function () use ($business, $data, $request) {
            $record = Business::query()->lockForUpdate()->findOrFail($business->id);
            $record->update($data);
            app(LabelWorkflow::class)->event($business->id, null, null, 'dispatch_policy', 'Scan verification before dispatch: '.($data['label_verification_required'] ? 'required' : 'optional'), $request->user()->id);
        });

        return back()->with('status', 'Sticker verification policy updated.');
    }

    public function index(Request $request): View
    {
        $business = $this->business($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'carton' => ['nullable', 'integer', 'min:1'], 'packing' => ['nullable', 'integer', 'min:1'], 'shipment' => ['nullable', 'integer', 'min:1'], 'order' => ['nullable', 'integer', 'min:1']]);
        foreach (['carton' => Carton::class, 'packing' => PackingJob::class, 'shipment' => ExportShipment::class, 'order' => SalesOrder::class] as $key => $model) {
            if (! empty($filters[$key])) {
                $model::query()->where('business_id', $business->id)->findOrFail($filters[$key]);
            }
        }
        $cartons = Carton::query()->where('business_id', $business->id)->whereIn('status', ['packed', 'reserved', 'ready'])->whereHas('packingJob', fn ($q) => $q->where('status', 'completed'))
            ->when($filters['carton'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['packing'] ?? null, fn ($q, $id) => $q->where('packing_job_id', $id))
            ->when($filters['shipment'] ?? null, fn ($q, $id) => $q->whereHas('shipmentAllocation', fn ($a) => $a->where('shipment_id', $id)))
            ->when($filters['order'] ?? null, fn ($q, $id) => $q->whereHas('shipmentAllocation.shipment', fn ($s) => $s->where('sales_order_id', $id)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('carton_code', 'like', '%'.$term.'%')->orWhereHas('packingJob.product', fn ($p) => $p->where('name', 'like', '%'.$term.'%'))))
            ->with(['packingJob.product', 'shipmentAllocation.shipment'])->orderBy('id')->workspacePaginate(50)->withQueryString();
        $templates = LabelTemplate::query()->where('business_id', $business->id)->where('active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $first = $cartons->first();
        $recommended = $templates->filter(fn ($t) => (! $t->product_id || $t->product_id === $first?->packingJob?->product_id) && (! $t->customer_id || $t->customer_id === $first?->shipmentAllocation?->shipment?->customer_id))->sortByDesc(fn ($t) => ($t->customer_id ? 4 : 0) + ($t->product_id ? 2 : 0) + ($t->is_default ? 1 : 0))->first()?->id;

        return view('labels.print', compact('cartons', 'templates', 'recommended'));
    }

    public function generate(Request $request, LabelWorkflow $workflow): RedirectResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['template_id' => ['required', 'integer'], 'carton_ids' => ['required', 'array', 'min:1', 'max:50'], 'carton_ids.*' => ['required', 'integer', 'distinct'], 'copies' => ['required', 'integer', 'between:1,10'], 'request_key' => ['required', 'uuid']]);
        $template = LabelTemplate::query()->where('business_id', $business->id)->findOrFail($data['template_id']);
        $batch = $workflow->generate($business, $template, $data['carton_ids'], (int) $data['copies'], $data['request_key'], $request->user()->id);

        return redirect()->route('labels.batches.show', $batch->id)->with('status', 'Preview the selected cartons, then print or queue this batch. Nothing has been physically printed yet.');
    }

    private function batch(Request $request, int $id): LabelPrintBatch
    {
        $business = $this->business($request);

        return LabelPrintBatch::query()->where('business_id', $business->id)->with('generations.carton', 'generations.template')->findOrFail($id);
    }

    public function show(Request $request, int $batch, LabelWorkflow $workflow): View
    {
        $batch = $this->batch($request, $batch);
        $stale = $workflow->staleMany($batch->generations);
        $agents = LabelPrintAgent::query()->where('business_id', $batch->business_id)->where('active', true)->get();

        return view('labels.batch', compact('batch', 'stale', 'agents'));
    }

    public function queue(Request $request, int $batch, LabelPrintQueue $queue): RedirectResponse
    {
        $batch = $this->batch($request, $batch);
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'request_key' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($batch, $queue, $data, $request) {
            foreach ($batch->generations as $g) {
                $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $data['request_key'].':'.$g->id);
                $queue->enqueue($request->attributes->get('activeBusiness'), (int) $data['agent_id'], $g->id, $key, $request->user()->id, (int) $batch->copies, $data['reason'] ?? null);
            }
        }, 3);

        return redirect()->route('labels.printers')->with('status', 'Batch queued. Follow transfer status and scan each applied sticker; a queued job does not confirm paper output.');
    }

    public function artwork(Request $request, int $generation): Response
    {
        $business = $this->business($request);
        $generation = LabelGeneration::query()->where('business_id', $business->id)->findOrFail($generation);
        abort_unless($generation->artwork_png, 404);
        abort_unless(app(LabelWorkflow::class)->validSnapshot($generation), 422, 'Saved artwork integrity check failed. Generate a replacement.');

        return response(base64_decode($generation->artwork_png, true), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function output(Request $request, int $batch, LabelWorkflow $workflow): Response
    {
        $batch = $this->batch($request, $batch);
        if ($workflow->staleMany($batch->generations)->contains(true)) {
            throw ValidationException::withMessages(['label' => 'This batch contains stale stickers. Generate a replacement before printing.']);
        }
        $data = $request->validate(['format' => ['required', Rule::in(['pdf', 'zpl'])], 'columns' => ['nullable', 'integer', 'between:1,5'], 'rows' => ['nullable', 'integer', 'between:1,10'], 'sheet_width' => ['nullable', 'numeric', 'between:25,420'], 'sheet_height' => ['nullable', 'numeric', 'between:15,594'], 'gap_x' => ['nullable', 'numeric', 'between:0,20'], 'gap_y' => ['nullable', 'numeric', 'between:0,20'], 'offset_x' => ['nullable', 'numeric', 'between:0,50'], 'offset_y' => ['nullable', 'numeric', 'between:0,50'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if ($data['format'] === 'zpl') {
            foreach ($batch->generations as $g) {
                abort_unless(($g->template_snapshot['profile']['protocol'] ?? 'zpl') === 'zpl', 422, 'Use PDF output for this printer profile.');
            }
            $body = $batch->generations->map(fn ($g) => str_repeat($g->zpl_snapshot, $batch->copies))->implode('');
            $type = 'application/octet-stream';
        } else {
            $first = $batch->generations->first()->template_snapshot;
            $w = (float) $first['width_mm'];
            $h = (float) $first['height_mm'];
            $cols = (int) ($data['columns'] ?? 1);
            $rows = (int) ($data['rows'] ?? 1);
            $sw = (float) ($data['sheet_width'] ?? $w);
            $sh = (float) ($data['sheet_height'] ?? $h);
            $gx = (float) ($data['gap_x'] ?? 0);
            $gy = (float) ($data['gap_y'] ?? 0);
            $ox = (float) ($data['offset_x'] ?? 0);
            $oy = (float) ($data['offset_y'] ?? 0);
            if ($ox + $cols * $w + ($cols - 1) * $gx > $sw + .001 || $oy + $rows * $h + ($rows - 1) * $gy > $sh + .001) {
                throw ValidationException::withMessages(['columns' => 'These rows, columns, gaps and offsets do not fit the sheet.']);
            }
            $images = [];
            foreach ($batch->generations as $g) {
                for ($copy = 0; $copy < $batch->copies; $copy++) {
                    $images[] = $g->artwork_png;
                }
            }
            $html = '<html><head><style>@page{margin:0}body{margin:0}.page{position:relative;page-break-after:always}.page:last-child{page-break-after:auto}img{position:absolute;display:block}</style></head><body>';
            foreach (array_chunk($images, $cols * $rows) as $page) {
                $html .= '<div class="page" style="width:'.$sw.'mm;height:'.($sh - .01).'mm">';
                foreach ($page as $i => $image) {
                    $html .= '<img alt="Sticker" src="data:image/png;base64,'.$image.'" style="width:'.$w.'mm;height:'.$h.'mm;left:'.($ox + ($i % $cols) * ($w + $gx)).'mm;top:'.($oy + intdiv($i, $cols) * ($h + $gy)).'mm">';
                }
                $html .= '</div>';
            }
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $pdf = new Dompdf($options);
            $pdf->setPaper([0, 0, $sw * 72 / 25.4, $sh * 72 / 25.4]);
            $pdf->loadHtml($html.'</body></html>');
            $pdf->render();
            $body = $pdf->output();
            $type = 'application/pdf';
        }
        foreach ($batch->generations as $label) {
            $workflow->event($batch->business_id, $label->id, null, 'output_downloaded', $data['reason'].'; '.$data['format'].'; '.$batch->copies.' copies. Paper output unconfirmed.', $request->user()->id);
        }

        return response($body, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="sticker-batch-'.$batch->id.'.'.$data['format'].'"', 'Cache-Control' => 'private, no-store']);
    }

    public function scan(Request $request, string $token, LabelWorkflow $workflow): View
    {
        $business = $this->business($request);
        $label = LabelGeneration::query()->where('business_id', $business->id)->where('scan_token', $token)->with('carton', 'template')->firstOrFail();
        $stale = $workflow->stale($label);
        $events = LabelEvent::query()->where('business_id', $business->id)->where('generation_id', $label->id)->latest('id')->workspacePaginate(20);

        return view('labels.scan', compact('label', 'stale', 'events'));
    }

    public function trace(Request $request, string $token): View|RedirectResponse
    {
        $business = $this->business($request, 'labels.view');
        $qr = QrIdentity::query()->where('business_id', $business->id)->where('public_token', $token)->firstOrFail();
        if ($qr->level === 'carton') {
            return redirect()->route('cartons.show', $qr->record_id);
        }
        if ($qr->level === 'product') {
            $record = Product::query()->where('business_id', $business->id)->findOrFail($qr->record_id);
            $title = $record->name;
            $fields = ['Product code' => $record->sku, 'Unit' => $record->base_unit];
        } else {
            abort_unless($qr->level === 'batch', 404);
            $record = StockBatch::query()->where('business_id', $business->id)->with('product')->findOrFail($qr->record_id);
            $title = $record->batch_code;
            $fields = ['Product' => $record->product->name, 'Origin' => $record->source_reference, 'Received on' => (string) $record->received_on];
        }

        return view('labels.trace', compact('title', 'fields'));
    }

    public function verify(Request $request, int $generation, LabelWorkflow $workflow): RedirectResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['scan' => ['required', 'string', 'max:300'], 'reason' => ['required', 'string', 'min:10', 'max:1000'], 'applied' => ['required', 'accepted']]);
        $workflow->verify($business, $generation, $data['scan'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Applied sticker and revision verified.');
    }

    public function recover(Request $request, int $job, LabelPrintQueue $queue): RedirectResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['action' => ['required', Rule::in(['cancel', 'retry', 'resolve_transferred'])], 'reason' => ['required', 'string', 'min:10', 'max:1000'], 'checked_printer' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']]);
        $queue->recover($business, $job, $data['action'], $data['reason'], $data['request_key'], $request->user()->id);

        return back()->with('status', 'Print recovery recorded. Review the new job or scan the applied sticker as appropriate.');
    }
}
