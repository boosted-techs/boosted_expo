<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Carton;
use App\Models\Customer;
use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Services\LabelWorkflow;
use App\Services\ZebraLabelRenderer;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $request->merge(['visible_fields' => $request->input('visible_fields', [])]);
        $fields = array_keys(ZebraLabelRenderer::FIELD_LABELS);
        $input = $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('label_templates', 'name')->where('business_id', $id)->ignore($template?->id),
            ],
            'width_mm' => ['required', 'numeric', 'decimal:0,2', 'between:25,150'],
            'height_mm' => ['required', 'numeric', 'decimal:0,2', 'between:15,200'],
            'dpi' => ['required', Rule::in([203, 300])],
            'qr_level' => ['required', Rule::in(['none', 'product', 'batch', 'carton'])],
            'include_barcode' => ['required', 'boolean'],
            'visible_fields' => ['present', 'array', 'max:'.count(ZebraLabelRenderer::FIELD_LABELS)],
            'visible_fields.*' => ['string', 'distinct', Rule::in($fields)],
            'required_fields' => ['nullable', 'array'], 'required_fields.*' => ['string', 'distinct', Rule::in($fields)],
            'qr_visibility' => ['sometimes', Rule::in(['internal', 'public', 'protected'])],
            'media_profile_id' => ['nullable', 'integer', Rule::exists('label_media_profiles', 'id')->where('business_id', $id)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $id)],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $id)],
            'is_default' => ['sometimes', 'boolean'],
            'layout' => ['sometimes', 'array:mode,font_mm,fields,barcode_field'],
            'layout.barcode_field' => ['nullable', Rule::in(['revision', ...$fields])],
            'layout.mode' => ['required_with:layout', Rule::in(['bitmap'])],
            'layout.font_mm' => ['nullable', 'numeric', 'between:1.5,6'],
            'layout.fields' => ['nullable', 'array:'.implode(',', $fields)],
            'layout.fields.*' => ['array:x_mm,y_mm,width_mm,font_mm,lines,align,order'],
            'layout.fields.*.x_mm' => ['nullable', 'numeric', 'between:0,150'],
            'layout.fields.*.y_mm' => ['nullable', 'numeric', 'between:0,200'],
            'layout.fields.*.width_mm' => ['nullable', 'numeric', 'between:5,150'],
            'layout.fields.*.font_mm' => ['nullable', 'numeric', 'between:1.5,6'],
            'layout.fields.*.lines' => ['nullable', 'integer', 'between:1,5'],
            'layout.fields.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'layout.fields.*.order' => ['nullable', 'integer', 'between:1,50'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:8192'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);
        if (count($input['visible_fields']) === 0 && $input['qr_level'] === 'none' && ! $input['include_barcode']) {
            throw ValidationException::withMessages([
                'visible_fields' => 'Choose at least one text field, barcode or QR code.',
            ]);
        }
        $input['required_fields'] ??= [];
        if (array_diff($input['required_fields'], $input['visible_fields'])) {
            throw ValidationException::withMessages(['required_fields' => 'Every required text field must also be selected for printing.']);
        }
        $input['qr_visibility'] ??= 'internal';
        if ($input['qr_visibility'] === 'protected' && $input['qr_level'] !== 'product') {
            throw ValidationException::withMessages(['qr_level' => 'Choose Product for a PIN-protected QR page.']);
        }
        $input['is_default'] ??= false;
        if (! empty($input['media_profile_id'])) {
            $profile = LabelMediaProfile::query()->where('business_id', $id)->findOrFail($input['media_profile_id']);
            foreach (['width_mm', 'height_mm', 'dpi'] as $key) {
                $input[$key] = $profile->$key;
            }
        }
        if (isset($input['layout'])) {
            $input['layout']['fields'] = array_map(fn ($config) => array_filter($config, fn ($v) => $v !== null && $v !== ''), $input['layout']['fields'] ?? []);
            usort($input['visible_fields'], fn ($a, $b) => ($input['layout']['fields'][$a]['order'] ?? 25) <=> ($input['layout']['fields'][$b]['order'] ?? 25));
            $logo = $template?->layout['logo_png'] ?? null;
            if ($request->boolean('remove_logo')) {
                $logo = null;
            }
            if ($request->hasFile('logo')) {
                $path = $request->file('logo')->getRealPath();
                $size = getimagesize($path);
                if (! $size || $size[0] * $size[1] > 16000000) {
                    throw ValidationException::withMessages(['logo' => 'Choose a logo with at most 16 million pixels.']);
                }
                $image = imagecreatefromstring(file_get_contents($path));
                $canvas = imagecreatetruecolor(600, 240);
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $white);
                $scale = min(600 / $size[0], 240 / $size[1]);
                $w = max(1, (int) round($size[0] * $scale));
                $h = max(1, (int) round($size[1] * $scale));
                imagecopyresampled($canvas, $image, (int) ((600 - $w) / 2), (int) ((240 - $h) / 2), 0, 0, $w, $h, $size[0], $size[1]);
                ob_start();
                imagepng($canvas, null, 9);
                $compressed = ob_get_clean();
                imagedestroy($image);
                imagedestroy($canvas);
                if (strlen($compressed) > 524288) {
                    throw ValidationException::withMessages(['logo' => 'The cropped logo must be within 512 KB after compression.']);
                }
                $logo = base64_encode($compressed);
            }
            if ($logo) {
                $input['layout']['logo_png'] = $logo;
            }
        }
        unset($input['logo'], $input['remove_logo']);
        $input['approved_by'] = $request->user()->id;
        $input['approved_at'] = now();

        return $input;
    }

    public function index(Request $request): View
    {
        $this->check($request, 'labels.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $templates = LabelTemplate::query()->where('business_id', $businessId)->orderBy('name')->workspacePaginate(25);
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'labels.manage'
        );
        $fields = ZebraLabelRenderer::FIELD_LABELS;
        $options = $canManage ? $this->options($businessId) : [];

        return view('labels.index', [...compact('templates', 'canManage', 'fields'), ...$options]);
    }

    private function options(int $businessId): array
    {
        return [
            'profiles' => LabelMediaProfile::query()->where('business_id', $businessId)->orderBy('name')->get(),
            'customers' => Customer::query()->where('business_id', $businessId)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('business_id', $businessId)->where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'labels.manage');
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data) {
            $businessId = $request->attributes->get('activeBusiness')->id;
            Business::query()->lockForUpdate()->findOrFail($businessId);
            $this->replaceDefault($businessId, $data);
            LabelTemplate::create([...$data, 'business_id' => $businessId, 'created_by' => $request->user()->id]);
        }, 3);

        return redirect()->route('labels.index')->with('status', 'Label template saved.');
    }

    public function edit(Request $request, int $template): View
    {
        $this->check($request, 'labels.manage');
        $template = $this->template($request, $template);
        $fields = ZebraLabelRenderer::FIELD_LABELS;

        return view('labels.edit', [...compact('template', 'fields'), ...$this->options($template->business_id)]);
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        $this->check($request, 'labels.manage');
        $template = $this->template($request, $template);
        $data = $this->validated($request, $template);

        DB::transaction(function () use ($template, $data) {
            Business::query()->lockForUpdate()->findOrFail($template->business_id);
            $locked = LabelTemplate::query()->where('business_id', $template->business_id)
                ->lockForUpdate()->findOrFail($template->id);
            $this->replaceDefault($template->business_id, $data, $template->id);
            $locked->update([
                ...$data, 'version' => $locked->version + 1,
            ]);
        });

        return redirect()->route('labels.index')->with('status',
            'Template updated. Previous label generations remain available in print history.'
        );
    }

    private function replaceDefault(int $businessId, array $data, ?int $except = null): void
    {
        if (! empty($data['is_default'])) {
            LabelTemplate::query()->where('business_id', $businessId)->where('customer_id', $data['customer_id'] ?? null)
                ->where('product_id', $data['product_id'] ?? null)->when($except, fn ($q) => $q->where('id', '!=', $except))->update(['is_default' => false]);
        }
    }

    public function generate(
        Request $request, int $carton, int $template, ZebraLabelRenderer $renderer,
    ): StreamedResponse {
        $this->check($request, 'labels.generate');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $carton = Carton::query()->where('business_id', $businessId)->findOrFail($carton);
        $template = LabelTemplate::query()->where('business_id', $businessId)
            ->where('active', true)->findOrFail($template);
        if (($template->layout['mode'] ?? '') === 'bitmap') {
            $batch = app(LabelWorkflow::class)->generate($request->attributes->get('activeBusiness'), $template, [$carton->id], 1, (string) Str::uuid(), $request->user()->id);
            $zpl = $batch->generations()->firstOrFail()->zpl_snapshot;

            return response()->streamDownload(fn () => print ($zpl), $carton->carton_code.'.zpl', ['Content-Type' => 'application/octet-stream']);
        }
        $zpl = $renderer->make($carton, $template);
        LabelGeneration::create([
            'business_id' => $businessId,
            'carton_id' => $carton->id,
            'template_id' => $template->id,
            'template_version' => $template->version,
            'zpl_sha256' => hash('sha256', $zpl),
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

        return response()->streamDownload(fn () => print ($zpl),
            $carton->carton_code.'.zpl',
            ['Content-Type' => 'application/octet-stream']);
    }

    public function history(Request $request): View
    {
        $this->check($request, 'labels.generate');
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $generations = LabelGeneration::query()->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->when(ctype_digit((string) $request->input('search')), fn ($q) => $q->where('id', (int) $request->input('search')))->orWhereHas('carton', fn ($c) => $c->where('carton_code', 'like', '%'.$request->input('search').'%'))))->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->with(['carton', 'template'])->latest('id')->workspacePaginate(25)->withQueryString();

        return view('labels.history', compact('generations'));
    }

    public function downloadHistoric(Request $request, int $generation): StreamedResponse
    {
        $this->check($request, 'labels.generate');
        $generation = LabelGeneration::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($generation);
        abort_unless($generation->zpl_snapshot !== null, 404);
        $name = $generation->carton?->carton_code ?? 'carton';
        $file = $name.'-label-'.$generation->id.'.zpl';

        return response()->streamDownload(fn () => print ($generation->zpl_snapshot),
            $file, ['Content-Type' => 'application/octet-stream']);
    }
}
