<?php

namespace App\Http\Controllers;

use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelPrintAgent;
use App\Models\LabelPrintJob;
use App\Services\LabelPrintQueue;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LabelPrintAgentController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'labels.view');
        $id = $request->attributes->get('activeBusiness')->id;
        $agents = LabelPrintAgent::query()->where('business_id', $id)
            ->orderByDesc('id')->get();
        $jobs = LabelPrintJob::query()->where('business_id', $id)
            ->with(['agent', 'generation.carton'])
            ->latest('id')->workspacePaginate(25);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $generations = LabelGeneration::query()->where('business_id', $id)
            ->when($request->filled('search'), fn ($q) => $q->whereHas('carton', fn ($c) => $c->where('carton_code', 'like', '%'.$request->input('search').'%')))
            ->latest('id')->with('carton')->workspacePaginate(25, ['*'], 'generation_page')->withQueryString();
        $profiles = LabelMediaProfile::query()->where('business_id', $id)->where('protocol', 'zpl')->orderBy('name')->get();
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'labels.manage');
        $canPrint = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'labels.generate');

        return view('labels.printers', compact('agents', 'jobs', 'generations',
            'canManage', 'canPrint', 'profiles'));
    }

    public function create(Request $request, LabelPrintQueue $queue): RedirectResponse
    {
        $this->check($request, 'labels.manage');
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:100'], 'media_profile_id' => ['nullable', 'integer']]);
        [$agent,$token] = $queue->register($request->attributes->get('activeBusiness'),
            $data['name'], $request->user()->id, isset($data['media_profile_id']) ? (int) $data['media_profile_id'] : null);

        return redirect()->route('labels.printers')
            ->with('new_agent_token', $token)->with('new_agent_id', $agent->id)
            ->with('status', 'Local print agent registered. Save its one-time token on the print workstation.');
    }

    public function revoke(Request $request, int $agent, LabelPrintQueue $queue): RedirectResponse
    {
        $this->check($request, 'labels.manage');
        $queue->revoke($request->attributes->get('activeBusiness'), $agent, $request->user()->id);

        return back()->with('status', 'Workstation revoked. Waiting jobs were cancelled; in-progress deliveries need physical inspection.');
    }

    public function submit(Request $request, LabelPrintQueue $queue): RedirectResponse
    {
        $this->check($request, 'labels.generate');
        $data = $request->validate([
            'agent_id' => ['required', 'integer'],
            'generation_id' => ['required', 'integer'],
            'request_key' => ['required', 'uuid'], 'copies' => ['sometimes', 'integer', 'between:1,10'], 'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $queue->enqueue($request->attributes->get('activeBusiness'),
            $data['agent_id'], $data['generation_id'],
            $data['request_key'], $request->user()->id, (int) ($data['copies'] ?? 1), $data['reason'] ?? null);

        return back()->with('status', 'Label queued for an authorised local print agent. No paper print is confirmed yet.');
    }

    public function poll(Request $request, int $agent, LabelPrintQueue $queue): JsonResponse
    {
        $token = (string) $request->bearerToken();
        $device = $queue->authenticate($agent, $token);
        $job = $queue->claim($device);

        return response()->json(['job' => $job], 200)
            ->header('Cache-Control', 'no-store');
    }

    public function ack(Request $request, int $agent, int $job,
        LabelPrintQueue $queue): JsonResponse
    {
        $device = $queue->authenticate($agent, (string) $request->bearerToken());
        $data = $request->validate([
            'lease' => ['required', 'string', 'size:64'],
            'transferred' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);
        $result = $queue->acknowledge($device, $job, $data['lease'],
            (bool) $data['transferred'], $data['note'] ?? null);

        return response()->json([
            'accepted' => true, 'status' => $result->status,
            'physical_print_confirmed' => false,
        ])->header('Cache-Control', 'no-store');
    }
}
