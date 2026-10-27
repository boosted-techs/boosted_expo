@extends('layouts.marketing')
@php
    $policyTitle = match ($kind) {
        'privacy' => 'Privacy Policy',
        'retention' => 'Data Retention Policy',
        default => 'Terms of Service',
    };
@endphp
@section('title', $policyTitle)
@section('description', $policyTitle.' for Boosted One, operated by Boosted Technologies Limited, Uganda.')
@section('robots', 'index,follow')
@section('content')
<section class="section-container section legal">
    <a href="{{ route('home') }}">← Boosted One home</a>
    <h1>{{ $policyTitle }}</h1>
    <p><strong>Effective date: 24 October 2026 · Last updated: 24 October 2026</strong></p>

    @if($kind === 'privacy')
        <p>This policy explains how Boosted Technologies Limited, Uganda ("Boosted", "we" or "us"), handles personal information through Boosted One at boostedone.online and when providing onboarding, billing and support.</p>
        <h2>1. Our role and your business's role</h2>
        <p>We are responsible for information collected for our own enquiries, account administration and support. When a customer enters employee, customer, supplier or other business records into its workspace, that business determines why those records are processed. We process those records to provide the service and carry out authorised instructions, subject to applicable law and the customer's agreement.</p>
        <h2>2. Information we process</h2>
        <p>Information may include names, work contact details, onboarding requirements, account permissions, subscription and payment records, and information contained in procurement, stock, staffing, sales, shipment and finance records. Technical information includes IP addresses, sign-in activity, security events and service logs.</p>
        <p>Provide only information needed for your business operations. Do not submit passwords, one-time codes, payment-provider secrets or unrelated sensitive personal information in enquiry forms or ordinary support email.</p>
        <h2>3. Why information is used</h2>
        <p>We use information to respond to enquiries, arrange onboarding, operate workspaces, manage access, deliver requested communications, administer subscriptions, investigate faults and misuse, maintain backups and meet legal obligations. Processing must have an applicable lawful basis, such as consent where required, performing an agreement or another ground permitted by law. Consent to an enquiry is not permission for unrelated advertising.</p>
        <h2>4. Cookies and preferences</h2>
        <p>Boosted One uses cookies for sessions, sign-in and security. The application may also save display preferences on your device. Blocking essential cookies can prevent sign-in. Advertising trackers are not part of the current public website; optional tracking would require an updated notice and any consent required by law.</p>
        <h2>5. Access, providers and disclosure</h2>
        <p>Workspace access depends on permissions assigned by authorised administrators. Hosting, cloud storage, email and other providers may process information needed to deliver the service. Information may also be disclosed when required by law or reasonably necessary to investigate misuse or protect legal rights. We do not sell personal information or permit its use for a provider's unrelated advertising.</p>
        <p>Hosting and communication infrastructure may process information outside Uganda. Contact us for provider and location information relevant to your agreement. Overseas processing is subject to applicable data-protection requirements; this notice does not provide blanket consent to unrestricted international transfers.</p>
        <h2>6. Security and retention</h2>
        <p>Access controls, encrypted connections and protected backups reduce unauthorised access and loss. No internet service can guarantee complete security. Report suspected account compromise promptly. Retention depends on the purpose, the customer's agreement and applicable law; see our <a href="{{ route('retention') }}">Data Retention Policy</a>.</p>
        <h2>7. Requests and complaints</h2>
        <p>Subject to applicable law, you may request access or correction, object to processing, request deletion or withdraw consent for processing that depends on consent. Withdrawal does not undo earlier lawful processing and may affect a requested service. Legal obligations or unresolved matters may require some information to remain.</p>
        <p>For information in a customer's workspace, contact that business first; we can assist it with authorised requests. We may verify identity and authority before disclosing or changing records. You may also complain to Uganda's <a href="https://pdpo.go.ug/" rel="noopener noreferrer">Personal Data Protection Office</a>.</p>
        <h2>8. Contact and changes</h2>
        <p>Send privacy enquiries to <a href="mailto:boostedone@boostedtechs.com">boostedone@boostedtechs.com</a>, addressed to Boosted Technologies Limited. Describe your request without attaching sensitive records; we will arrange a suitable way to verify and handle it. Changes will be published here with an updated date, with additional notice where required.</p>
    @elseif($kind === 'retention')
        <p>This policy describes retention and deletion of information used by Boosted One, operated by Boosted Technologies Limited. Different record categories can require different retention periods depending on their purpose, the customer's agreement and applicable law.</p>
        <h2>1. Active workspaces</h2>
        <p>Business records are retained while needed to provide the agreed service and support legitimate operational, accounting, traceability and legal requirements. Administrators should limit information entered and identify specific retention requirements during onboarding. The service agreement should record any fixed periods required by the business.</p>
        <h2>2. Enquiries and account information</h2>
        <p>Onboarding and support information is retained while an enquiry or service matter remains relevant and where needed to document the resulting relationship or resolve a dispute. Information no longer required for an agreed or lawful purpose is deleted or de-identified.</p>
        <h2>3. Closure and exports</h2>
        <p>Ending a subscription does not automatically mean immediate deletion of every record. An authorised representative should contact us to arrange an available data export, confirm closure and agree deletion instructions. Access, export arrangements and any applicable charges are governed by the service agreement.</p>
        <p>After closure, information no longer required for an agreed or lawful purpose is deleted or de-identified. Records needed for accounting, legal obligations, a dispute, a security investigation or a lawful hold may remain for the relevant purpose and period.</p>
        <h2>4. Backups</h2>
        <p>Protected backups support recovery and may contain earlier versions of records. Deleting a live record does not immediately remove it from every backup. Backup copies remain restricted and are replaced or securely deleted through the applicable backup lifecycle. Relevant verified deletion instructions must be reapplied following restoration.</p>
        <p>A specific backup-retention or deletion deadline must be agreed in the service terms for customers that require one. This policy does not promise an automatic universal deletion interval.</p>
        <h2>5. Requests and verification</h2>
        <p>Send export, retention or deletion requests to <a href="mailto:boostedone@boostedtechs.com">boostedone@boostedtechs.com</a>. We verify identity and authority and consider affected individuals' rights and any legal or contractual restrictions. Where a request cannot be completed in full, the applicable reason and available next steps should be explained.</p>
        <p>Information must not be retained merely because storage is available. When its justified retention period ends, personal information must be securely deleted or de-identified as required by applicable law.</p>
    @else
        <p>These terms govern use of Boosted One, operated by Boosted Technologies Limited, Uganda. An authorised representative must agree each customer's commercial arrangements. A signed service agreement prevails where it expressly provides a different arrangement, subject to applicable law.</p>
        <h2>1. Onboarding and access</h2>
        <p>An enquiry or onboarding request does not create an account, activate a subscription or charge your business. Workspaces require approval; the authorised owner and invited team members receive access through the approved onboarding process.</p>
        <p>Customers are responsible for lawful use, accurate account information, protecting credentials, maintaining required additional authentication and removing access for staff who no longer need it. Report suspected unauthorised access promptly.</p>
        <h2>2. Scope and customer review</h2>
        <p>Modules, users, implementation work, support, hosting and external integrations are described in the agreed offer or service agreement. A feature description does not establish that a customer's bank, carrier, printer or other external service has been connected or independently approved.</p>
        <p>Customers must check quantities, accounting entries, payment evidence, export documents and submissions before relying on them. Software-generated records do not replace required professional review or official regulatory approval.</p>
        <h2>3. Fees and subscriptions</h2>
        <p>Prices, taxes, billing periods, renewal arrangements, cancellation notice and refunds are set out in the written agreement or official invoice. Monthly, quarterly and annual arrangements depend on the selected offer. Any automatic renewal or recurring charge must be expressly agreed.</p>
        <p>Use only payment instructions confirmed on an official Boosted Technologies invoice or through a verified support channel. Payment must be verified before the corresponding entitlement is activated. An unverified message or callback is not proof of payment.</p>
        <h2>4. One-time licences</h2>
        <p>A one-time purchase requires a separate written agreement defining the licence and deliverables. It does not automatically include indefinite hosting, upgrades, implementation work or continuing support.</p>
        <h2>5. Records and privacy</h2>
        <p>Customers retain their rights in supplied records and must have authority and a lawful basis to process information about employees, customers, suppliers and others. We process records to deliver and support the service, handle authorised instructions and meet legal duties.</p>
        <p>The <a href="{{ route('privacy') }}">Privacy Policy</a> and <a href="{{ route('retention') }}">Data Retention Policy</a> describe information handling. Specific processing, export, retention and international-transfer requirements must be addressed in the customer's agreement.</p>
        <h2>6. Acceptable use and intellectual property</h2>
        <p>Do not use the service unlawfully, upload malicious material, access another customer's information, share credentials to bypass agreed limits or interfere with the platform. Security testing requires prior written authorisation defining its scope.</p>
        <p>Boosted and its licensors retain their rights in the software, branding and service materials. Access grants the agreed right to use the service; it does not transfer software ownership. Entering customer records does not transfer their ownership to Boosted.</p>
        <h2>7. Availability and suspension</h2>
        <p>Maintenance, faults and third-party infrastructure can affect availability. Specific response times, uptime commitments, recovery objectives and service credits apply only where agreed in writing. Customers should maintain suitable continuity procedures and required independent records or exports.</p>
        <p>Access may be restricted where reasonably necessary to address a security incident, unlawful activity, material breach or unpaid fees under agreed terms. Notice and an opportunity to resolve the issue will be given where practicable and appropriate. Emergency protection may require immediate action.</p>
        <h2>8. Ending the service and responsibility</h2>
        <p>Cancellation, termination, export assistance and post-termination access follow the agreement and applicable law. Arrange required exports before access ends. Retention and deletion follow the Data Retention Policy.</p>
        <p>Responsibilities and agreed limits of liability are governed by the written agreement and applicable law. Nothing here excludes a right or responsibility that the law does not permit to be excluded.</p>
        <h2>9. Contact and updates</h2>
        <p>Send service, billing or account concerns to <a href="mailto:boostedone@boostedtechs.com">boostedone@boostedtechs.com</a>. We encourage prompt discussion and practical resolution. Ugandan law applies, subject to mandatory applicable law and a valid written agreement on dispute resolution.</p>
        <p>Updated general terms will be published with a new effective date. Changes to existing commercial terms must follow the customer's agreement and applicable law.</p>
    @endif
    <nav aria-label="Related policies">
        <a href="{{ route('privacy') }}">Privacy Policy</a> ·
        <a href="{{ route('retention') }}">Data Retention Policy</a> ·
        <a href="{{ route('terms') }}">Terms of Service</a>
    </nav>
</section>
@endsection
