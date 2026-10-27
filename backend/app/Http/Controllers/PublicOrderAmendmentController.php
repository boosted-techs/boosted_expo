<?php

namespace App\Http\Controllers;

use App\Services\OrderAmendments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicOrderAmendmentController extends Controller
{
    public function show(string $token, OrderAmendments $service): Response
    {
        return response()->view('sales.orders.buyer-amendment', [...$service->resolve($token), 'token' => $token])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function decide(Request $r, string $token, OrderAmendments $service): Response
    {
        $data = $r->validate(['decision' => ['required', 'in:accepted,rejected'], 'buyer_name' => ['required', 'string', 'min:2', 'max:150'], 'buyer_reference' => ['required', 'string', 'min:3', 'max:200']]);
        $service->decide($token, $data);

        return redirect()->route('buyer-amendment.show', $token)->with('status', 'Your order change decision has been recorded.')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
