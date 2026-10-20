<?php

namespace App\Http\Controllers;

use App\Models\Carton;
use App\Models\LabelGeneration;
use App\Models\LabelTemplate;
use App\Services\ZebraLabelRenderer;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LabelTemplateController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission
        ), 403);
    }

    private function template(Request $request, int $id): LabelTemplate
    {
        return LabelTemplate::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($id);
    }

    private function validated(Request $request, ?LabelTemplate $template = null): array
    {
        $id = $request->attributes->get('activeBusiness')->id;
        $request->merge(['visible_fields'=>$request->input('visible_fields',[])]);
        $fields = array_keys(ZebraLabelRenderer::FIELD_LABELS);
        $input = $request->validate([
            'name' => [
                'required','string','min:2','max:100',
                Rule::unique('label_templates','name')->where('business_id',$id)->ignore($template?->id),
            ],
            'width_mm' => ['required','integer','between:75,150'],
            'height_mm' => ['required','integer','between:45,100'],
            'dpi' => ['required',Rule::in([203,300])],
            'qr_level' => ['required',Rule::in(['none','product','batch','carton'])],
            'include_barcode' => ['required','boolean'],
            'visible_fields' => ['present','array','max:'.count(ZebraLabelRenderer::FIELD_LABELS)],
            'visible_fields.*' => ['string','distinct',Rule::in($fields)],
        ]);
        if (count($input['visible_fields']) === 0 && $input['qr_level'] === 'none' && ! $input['include_barcode']) {
            throw ValidationException::withMessages([
                'visible_fields' => 'Choose at least one text field, barcode or QR code.',
            ]);
        }
        return $input;
    }

    public function index(Request $request): View
    {
        $this->check($request, 'labels.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $templates = LabelTemplate::query()->where('business_id',$businessId)->orderBy('name')->get();
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'labels.manage'
        );
        $fields = ZebraLabelRenderer::FIELD_LABELS;
        return view('labels.index',compact('templates','canManage','fields'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'labels.manage');
        $data = $this->validated($request);
        LabelTemplate::create([
            ...$data,
            'business_id' => $request->attributes->get('activeBusiness')->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('labels.index')->with('status','Label template saved.');
    }

    public function edit(Request $request, int $template): View
    {
        $this->check($request, 'labels.manage');
        $template = $this->template($request, $template);
        $fields = ZebraLabelRenderer::FIELD_LABELS;

        return view('labels.edit',compact('template','fields'));
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        $this->check($request, 'labels.manage');
        $template = $this->template($request, $template);
        $data = $this->validated($request, $template);

        DB::transaction(function () use ($template, $data) {
            $locked = LabelTemplate::query()->where('business_id', $template->business_id)
                ->lockForUpdate()->findOrFail($template->id);
            $locked->update([
                ...$data, 'version' => $locked->version + 1,
            ]);
        });

        return redirect()->route('labels.index')->with('status',
            'Template updated. Previous label generations remain available in print history.'
        );
    }

    public function generate(
        Request $request, int $carton, int $template, ZebraLabelRenderer $renderer,
    ): StreamedResponse {
        $this->check($request, 'labels.generate');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $carton = Carton::query()->where('business_id',$businessId)->findOrFail($carton);
        $template = LabelTemplate::query()->where('business_id',$businessId)
            ->where('active',true)->findOrFail($template);
        $zpl = $renderer->make($carton, $template);
        LabelGeneration::create([
            'business_id' => $businessId,
            'carton_id' => $carton->id,
            'template_id' => $template->id,
            'template_version' => $template->version,
            'zpl_sha256' => hash('sha256',$zpl),
            'zpl_snapshot' => $zpl,
            'template_snapshot' => [
                'name' => $template->name,
                'width_mm' => $template->width_mm,
                'height_mm' => $template->height_mm,
                'dpi' => $template->dpi,
                'qr_level' => $template->qr_level,
                'visible_fields' => $template->visible_fields,
                'include_barcode' => $template->include_barcode,
            ],
            'generated_by' => $request->user()->id,
        ]);

        return response()->streamDownload(fn () => print($zpl),
            $carton->carton_code.'.zpl',
            ['Content-Type' => 'application/octet-stream']);
    }

    public function history(Request $request): View
    {
        $this->check($request, 'labels.generate');
        $generations = LabelGeneration::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->with(['carton','template'])->latest()->paginate(25);

        return view('labels.history',compact('generations'));
    }

    public function downloadHistoric(Request $request, int $generation): StreamedResponse
    {
        $this->check($request, 'labels.generate');
        $generation = LabelGeneration::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($generation);
        abort_unless($generation->zpl_snapshot !== null, 404);
        $name = $generation->carton?->carton_code ?? 'carton';
        $file = $name.'-label-'.$generation->id.'.zpl';

        return response()->streamDownload(fn () => print($generation->zpl_snapshot),
            $file, ['Content-Type'=>'application/octet-stream']);
    }
}
