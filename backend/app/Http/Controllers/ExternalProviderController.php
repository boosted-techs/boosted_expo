<?php

namespace App\Http\Controllers;

use App\Models\ExternalProviderConnection;
use App\Models\ExternalProviderEvent;
use App\Services\ExternalProviderInbox;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExternalProviderController extends Controller
{
    public function webhook(Request $request,string $kind,string $provider,
        ExternalProviderInbox $inbox):JsonResponse
    {
        $event=$inbox->accept(
            $kind,$provider,$request->getContent(),
            (string)$request->header('X-Expo-Timestamp',''),
            (string)$request->header('X-Expo-Signature',''),
        );
        return response()->json([
            'received'=>true,'event_id'=>$event->provider_event_id,
            'review_required'=>true,
        ],202)->header('Cache-Control','no-store');
    }

    public function index(Request $request):View
    {
        $membership=$request->attributes->get('membership');
        abort_unless(app(BusinessPermissions::class)->allows($membership,
            'integrations.view'),403);
        $business=$request->attributes->get('activeBusiness');
        $connections=ExternalProviderConnection::query()
            ->where('business_id',$business->id)->orderBy('kind')
            ->orderBy('provider_code')->get();
        $events=ExternalProviderEvent::query()->where('business_id',$business->id)
            ->orderByDesc('id')->paginate(25);
        $canReview=app(BusinessPermissions::class)->allows($membership,
            'integrations.review');
        return view('integrations.index',compact('connections','events','canReview'));
    }

    public function review(Request $request,int $event,string $action,
        ExternalProviderInbox $inbox):RedirectResponse
    {
        abort_unless(in_array($action,['accept','reject'],true),404);
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'integrations.review'),403);
        $data=$request->validate([
            'note'=>['required','string','min:20','max:2000'],
        ]);
        $e=ExternalProviderEvent::query()
            ->where('business_id',$request->attributes->get('activeBusiness')->id)
            ->findOrFail($event);
        $inbox->review($e,$request->user()->id,$action==='accept',$data['note']);
        return back()->with('status',$action==='accept'
            ?'Provider evidence reviewed and linked for reference. No ledger or shipment status was changed.'
            :'External evidence rejected with an audit explanation.');
    }
}
