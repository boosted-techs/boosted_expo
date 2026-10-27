<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Boosted One package information</title></head>
<body style="margin:0;padding:0;background:#f3f7f3;font:16px/1.65 Arial,Helvetica,sans-serif;color:#183b2b">
<div style="max-width:620px;margin:0 auto;padding:35px 20px">
    <div style="background:#143f32;border-radius:12px 12px 0 0;padding:24px 30px;color:white">
        <strong style="font-size:25px;letter-spacing:-.5px">Boosted One</strong>
        <div style="font-size:13px;color:#b8e6ce">ERP for Ugandan exporters and growing businesses</div>
    </div>
    <div style="background:white;border-radius:0 0 12px 12px;padding:30px">
        <p>Hello {{ $contactName }},</p>
        <p>Thank you for your interest in Boosted One for <strong>{{ $businessName }}</strong>.
            We have received your onboarding request.</p>
        <p>As requested, here is an overview of the ERP packages your business can discuss with our team.</p>
        @if($isIndicative)
        <p style="padding:12px 15px;background:#edf7f0;border-radius:8px">
            The options below are an introduction, not a confirmed offer.
            Package availability, included modules and prices will be agreed after we review your requirements.
        </p>
        @endif
        @foreach($packages as $package)
        <div style="margin:17px 0;padding:19px;border:1px solid #d8e9de;border-radius:9px">
            <h2 style="margin:0 0 6px;font-size:20px;color:#155d3e">{{ $package['name'] }}</h2>
            @if(!empty($package['description']))<p style="margin:0 0 10px">{{ $package['description'] }}</p>@endif
            @if(!$isIndicative)
                @if($package['monthly_price_ugx'] !== null)<p style="margin:4px 0"><strong>Monthly:</strong> UGX {{ number_format($package['monthly_price_ugx']) }}</p>@endif
                @if($package['quarterly_price_ugx'] !== null)<p style="margin:4px 0"><strong>Quarterly:</strong> UGX {{ number_format($package['quarterly_price_ugx']) }}</p>@endif
                @if($package['annual_price_ugx'] !== null)<p style="margin:4px 0"><strong>Annually:</strong> UGX {{ number_format($package['annual_price_ugx']) }}</p>@endif
            @endif
        </div>
        @endforeach
        <p>Our team will contact you to understand your operations, recommend suitable modules,
            and confirm pricing, taxes and terms in a written proposal before any payment.</p>
        <p><strong>No subscription or payment has been started.</strong> Please only use verified
            Boosted Technologies invoice instructions when an agreement has been reached.</p>
        <p>Questions? Reply to this message or email
            <a style="color:#116b4c" href="mailto:boostedone@boostedtechs.com">boostedone@boostedtechs.com</a>.</p>
        <p>Regards,<br><strong>Boosted One Team</strong><br>Boosted Technologies Limited</p>
    </div>
    <p style="font-size:12px;color:#6e8074;text-align:center">This message follows your recent Boosted One onboarding enquiry.</p>
</div>
</body></html>
