<?php

namespace App\Http\Controllers;

use App\Services\PrivilegedMfa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaController extends Controller
{
    public function setup(Request $request,PrivilegedMfa $mfa):View|RedirectResponse
    {
        $user=$request->user();
        if($user->mfa_enabled_at) return redirect()->route('security.mfa.challenge');

        $secret=$request->session()->get('mfa_setup_secret');
        if(!is_string($secret) || !preg_match('/^[A-Z2-7]{32}$/D',$secret)){
            $secret=$mfa->newSecret();
            $request->session()->put('mfa_setup_secret',$secret);
        }
        $uri=$mfa->provisioningUri($user,$secret);
        $qrImage=null;
        try {
            $result=(new \Endroid\QrCode\Writer\PngWriter())
                ->write(new \Endroid\QrCode\QrCode($uri));
            $qrImage='data:image/png;base64,'.base64_encode($result->getString());
        }catch(\Throwable){
            // Authenticator apps also permit manually entering the secret.
        }
        return view('security.mfa.setup',compact('secret','uri','qrImage'));
    }

    public function enrol(Request $request,PrivilegedMfa $mfa):RedirectResponse
    {
        abort_if($request->user()->mfa_enabled_at,422,
            'This account already has an authenticator.');
        $data=$request->validate(['code'=>['required','digits:6']]);
        $secret=$request->session()->get('mfa_setup_secret');
        abort_unless(is_string($secret),422,'Start authenticator setup again.');
        $codes=$mfa->enrol($request->user(),$secret,$data['code']);
        $request->session()->forget('mfa_setup_secret');
        $request->session()->regenerate();
        $request->session()->put('mfa_verified_user_id',$request->user()->id);
        $request->session()->flash('mfa_recovery_codes_display',$codes);
        return redirect()->route('security.mfa.recovery');
    }

    public function recovery(Request $request):View|RedirectResponse
    {
        $codes=$request->session()->pull('mfa_recovery_codes_display');
        if(!is_array($codes) || $codes===[]){
            return redirect()->route('workspace');
        }
        return view('security.mfa.recovery',compact('codes'));
    }

    public function challenge(Request $request):View|RedirectResponse
    {
        if(!$request->user()->mfa_enabled_at){
            return redirect()->route('security.mfa.setup');
        }
        if($request->session()->get('mfa_verified_user_id')===$request->user()->id){
            return redirect()->route('workspace');
        }
        return view('security.mfa.challenge');
    }

    public function verify(Request $request,PrivilegedMfa $mfa):RedirectResponse
    {
        $user=$request->user();
        abort_unless($user->mfa_enabled_at,422,'Set up an authenticator first.');
        $data=$request->validate([
            'code'=>['nullable','digits:6'],
            'recovery_code'=>['nullable','string','min:8','max:40'],
        ]);
        if(empty($data['code']) && empty($data['recovery_code'])){
            throw ValidationException::withMessages([
                'code'=>'Enter your authenticator code or an unused recovery code.',
            ]);
        }
        $key='expo-mfa:'.$user->id.'|'.$request->ip();
        if(RateLimiter::tooManyAttempts($key,5)){
            throw ValidationException::withMessages([
                'code'=>'Too many attempts. Try again later.',
            ]);
        }
        if(!$mfa->verify($user,(string)($data['code']??''),
            $data['recovery_code']??null)){
            RateLimiter::hit($key,120);
            throw ValidationException::withMessages([
                'code'=>'The security code is invalid, expired or already used.',
            ]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('mfa_verified_user_id',$user->id);
        return redirect()->intended(route('workspace'))
            ->with('status','Your account security check was successful.');
    }
}
