<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordRecoveryController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot');
    }

    public function send(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        try {
            Password::sendResetLink(['email' => strtolower(trim($data['email']))]);
        } catch (TransportExceptionInterface $exception) {
            report($exception);
        }

        return back()->with('status', 'If the email belongs to an account, password reset instructions will be sent. If nothing arrives, try again later or contact your administrator.');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => 'required|string|min:12|confirmed']);
        $result = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $result === Password::PASSWORD_RESET
           ? redirect()->route('login')->with('status', 'Password updated. Please sign in.')
           : back()->withErrors(['email' => 'The password reset link is invalid or has expired.']);
    }
}
