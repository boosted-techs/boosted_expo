<?php

namespace App\Http\Controllers;

use App\Models\BillingPlan;
use App\Models\OnboardingRequest;
use App\Services\OnboardingPackageMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class BoostedOnePublicController extends Controller
{
    public function home(): View
    {
        return view('marketing.home', [
            'featured' => config('boostedone_features'),
            'solutions' => config('boostedone_solutions'),
        ]);
    }

    public function features(): View
    {
        return view('marketing.features', ['features' => config('boostedone_features')]);
    }

    public function feature(string $slug): View
    {
        $feature = config('boostedone_features.'.$slug);
        abort_unless(is_array($feature), 404);

        return view('marketing.feature', [
            'feature' => $feature,
            'slug' => $slug,
            'features' => config('boostedone_features'),
        ]);
    }

    public function solutions(): View
    {
        return view('marketing.solutions', ['solutions' => config('boostedone_solutions')]);
    }

    public function solution(string $slug): View
    {
        $solution = config('boostedone_solutions.'.$slug);
        abort_unless(is_array($solution), 404);

        return view('marketing.solution', [
            'solution' => $solution,
            'slug' => $slug,
            'features' => config('boostedone_features'),
        ]);
    }

    public function workflow(): View
    {
        return view('marketing.workflow');
    }

    public function pricing(): View
    {
        return view('marketing.pricing', [
            'plans' => BillingPlan::query()
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function onboarding(): View
    {
        return view('marketing.onboarding');
    }

    public function about(): View
    {
        return view('marketing.about');
    }

    public function contact(): View
    {
        return view('marketing.contact');
    }

    public function privacy(): View
    {
        return view('marketing.legal', ['kind' => 'privacy']);
    }

    public function retention(): View
    {
        return view('marketing.legal', ['kind' => 'retention']);
    }

    public function terms(): View
    {
        return view('marketing.legal', ['kind' => 'terms']);
    }

    public function sitemap(): Response
    {
        $urls = [
            route('home'),
            route('site.solutions'),
            route('site.features'),
            route('site.workflow'),
            route('site.pricing'),
            route('site.about'),
            route('site.contact'),
            route('site.onboarding'),
            route('privacy'),
            route('retention'),
            route('terms'),
        ];
        foreach (array_keys(config('boostedone_features')) as $slug) {
            $urls[] = route('site.feature', $slug);
        }
        foreach (array_keys(config('boostedone_solutions')) as $slug) {
            $urls[] = route('site.solution', $slug);
        }
        $items = implode('', array_map(
            static fn (string $url): string => '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>',
            $urls
        ));

        return response(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</urlset>',
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );
    }

    public function apply(Request $request, OnboardingPackageMailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => 'required|string|min:2|max:150',
            'contact_name' => 'required|string|min:2|max:150',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'required|string|min:9|max:40',
            'industry' => 'required|in:export,agriculture,manufacturing,distribution,wholesale,other',
            'business_size' => 'required|in:small,growing,enterprise',
            'requirements' => 'nullable|string|max:1500',
            'privacy_agreement' => 'accepted',
            'website' => 'nullable|size:0',
        ]);
        unset($data['privacy_agreement'], $data['website']);
        // Neither a package nor a billing term is selected by the applicant.
        // Both will be agreed with the team after the public enquiry.
        $data['requested_plan'] = 'to_discuss';
        $data['billing_cycle'] = 'to_discuss';
        $data['contact_email'] = strtolower(trim($data['contact_email']));
        $data['privacy_accepted_at'] = now();
        $application = OnboardingRequest::create($data);

        try {
            $mailer->send($application);
            $message = 'Thank you. Your onboarding request was received, and package information has been sent to your email. Our team will contact you to discuss your business.';
        } catch (\Throwable $exception) {
            // Keep the request even when the mail service is temporarily down.
            // A platform administrator can send the package details again.
            report($exception);
            $message = 'Thank you. Your onboarding request was received. Our team will email package information and contact you about your business.';
        }

        return redirect()->route('site.onboarding')->with('status', $message);
    }
}
