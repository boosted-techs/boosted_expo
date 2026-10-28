<?php

namespace Database\Seeders;

use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessOperatingPolicy;
use App\Models\Customer;
use App\Models\OnboardingRequest;
use App\Models\PlatformCase;
use App\Models\PlatformHealthEvidence;
use App\Models\PlatformLimitOverride;
use App\Models\PlatformSupportGrant;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\StaffPerson;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\TeamMembershipEvent;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\InviteBusinessUser;
use App\Services\PlatformAccess;
use App\Services\PlatformBilling;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;

class ExporterDemoPlatformSeeder extends Seeder
{
    public function run(ExporterDemoContext $d): void
    {
        foreach (['superadmin' => 'Sarah Platform Administrator', 'owner' => 'Grace Namutebi', 'accountant' => 'Daniel Kato', 'sales' => 'Aisha Nakato', 'buyer' => 'Moses Ssemakula', 'warehouse' => 'Ruth Akello', 'packing' => 'Peter Okello', 'logistics' => 'Joseph Mugisha', 'hr' => 'Esther Nansubuga', 'viewer' => 'John Observer'] as $role => $name) {
            $email = $role.'@demo.boosted.test';
            abort_if(User::where('email', $email)->exists(), 422, 'A demo email already exists outside a completed demo. No account was overwritten.');
            $u = new User;
            $u->forceFill(['name' => $name.' · DEMO', 'email' => $email, 'password' => $d->password, 'email_verified_at' => now(), 'is_platform_admin' => $role === 'superadmin', 'platform_role' => $role === 'superadmin' ? 'superadmin' : 'business'])->save();
            $d->users[$role] = $u;
            $d->credentials[$role] = $email;
        }
        $d->business = Business::create(['name' => 'DEMO · Nile Fresh Exporters Uganda', 'code' => 'DEMO-EXPORT', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'enabled_currencies' => ['UGX', 'USD', 'EUR', 'AED'], 'default_qr_level' => 'carton', 'is_active' => true, 'lifecycle_status' => 'operating', 'billing_mode' => 'trial', 'trial_ends_on' => today('Africa/Kampala')->addDays(30), 'owner_contact_email' => $d->user('owner')->email, 'label_verification_required' => true]);
        $b = $d->business;
        BusinessOperatingPolicy::create(['business_id' => $b->id, 'dispatch_approval_required' => false, 'buyer_link_days' => 14, 'required_export_documents' => [], 'updated_by' => $d->id('owner')]);
        foreach ($d->users as $role => $u) {
            if ($role === 'superadmin') {
                continue;
            }$membership = BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => $role, 'active' => true]);
            TeamMembershipEvent::create(['business_id' => $b->id, 'membership_id' => $membership->id, 'actor_id' => $d->id('owner'), 'action' => 'created', 'reason' => $d->note('Role-separated demo team member.'), 'previous_role' => $role, 'previous_active' => true, 'new_role' => $role, 'new_active' => true, 'created_at' => now()]);
            $d->put('staff_'.$role, StaffPerson::create(['business_id' => $b->id, 'user_id' => $u->id, 'staff_code' => 'DEMO-'.strtoupper($role), 'name' => $u->name, 'kind' => 'employee', 'skills' => ['packing', 'receiving', 'market_buying'], 'active' => true, 'advance_limit_base' => '500000', 'payroll_tax_class' => 'resident', 'nssf_applicable' => true, 'payroll_classification_note' => $d->note('Illustrative employee classification; verify real classifications before use.'), 'payroll_classified_by' => $d->id('owner'), 'payroll_classified_at' => now()]));
        }
        $d->put('casual', StaffPerson::create(['business_id' => $b->id, 'staff_code' => 'DEMO-CASUAL', 'name' => 'Agnes Market Casual · DEMO', 'kind' => 'casual', 'skills' => ['packing'], 'active' => true, 'advance_limit_base' => '100000', 'payroll_tax_class' => 'resident', 'nssf_applicable' => false, 'payroll_classification_note' => $d->note('Illustrative casual classification.'), 'payroll_classified_by' => $d->id('owner'), 'payroll_classified_at' => now()]));
        foreach (['AVO' => ['Avocado', 'Export-grade avocado', 'kg'], 'CHILLI' => ['Fresh chilli', 'Fresh chilli for export', 'kg'], 'PINE' => ['Pineapple', 'Fresh whole pineapple', 'kg']] as $sku => [$name,$description,$unit]) {
            $d->put($sku, Product::create(['business_id' => $b->id, 'sku' => 'DEMO-'.$sku, 'name' => $name, 'description' => $description.' · demonstration product', 'base_unit' => $unit, 'active' => true, 'reorder_point' => '40']));
        }
        $d->put('customer', Customer::create(['business_id' => $b->id, 'customer_code' => 'DEMO-DUBAI', 'name' => 'DEMO · Dubai Fresh Imports', 'email' => 'buyer@dubai-importer.example.test', 'country_code' => 'AE', 'currency' => 'USD', 'active' => true]));
        ProductCode::create(['business_id' => $b->id, 'product_id' => $d->records['AVO']->id, 'customer_id' => $d->records['customer']->id, 'country_code' => 'AE', 'code' => 'DEMO-AVO-AE', 'description' => 'Demonstration destination code', 'active' => true]);
        $d->put('customer_ug', Customer::create(['business_id' => $b->id, 'customer_code' => 'DEMO-LOCAL', 'name' => 'DEMO · Regional Produce Buyer', 'email' => 'buyer@regional.example.test', 'country_code' => 'UG', 'currency' => 'UGX', 'active' => true]));
        foreach (['supplier' => ['DEMO-GROWER', 'DEMO · Mukono Growers Cooperative', 'farmer'], 'vendor' => ['DEMO-MARKET', 'DEMO · Nakasero Market Vendor', 'vendor']] as $key => [$code,$name,$kind]) {
            $d->put($key, Supplier::create(['business_id' => $b->id, 'supplier_code' => $code, 'name' => $name, 'kind' => $kind, 'market_location' => $kind === 'vendor' ? 'Nakasero, Kampala' : 'Mukono', 'email' => $key.'@growers.example.test', 'notes' => $d->note('Training partner; no real supplier contact.'), 'active' => true]));
        }
        $d->put('location', StockLocation::create(['business_id' => $b->id, 'name' => 'DEMO Kampala packhouse', 'active' => true]));
        $d->put('cold_location', StockLocation::create(['business_id' => $b->id, 'name' => 'DEMO cold room', 'active' => true]));
        app(FinanceSetup::class)->activate($b);
        $fx = app(FinanceRates::class)->propose($b, ['currency' => 'USD', 'rate_to_base' => '3700', 'effective_on' => $d->date(30), 'source_note' => $d->note('Illustrative manual FX: UGX for one USD, not a market rate.')], $d->id('accountant'));
        app(FinanceRates::class)->approve($fx, $d->id('owner'));
        $d->put('usd_rate', $fx);
        foreach (['ugx_bank' => 'UGX', 'usd_bank' => 'USD'] as $key => $currency) {
            $d->put($key, app(FinanceBankProfiles::class)->create($b, ['label' => 'DEMO '.$currency.' bank', 'institution' => 'Fictional Training Bank · no real account', 'currency' => $currency], $d->id('owner')));
        }
        $plan = BillingPlan::create(['code' => 'DEMO-EXPORT-1', 'family_code' => 'DEMO-EXPORT', 'version' => 1, 'name' => 'DEMO Export Business', 'description' => $d->note('Training subscription only.'), 'modules' => ['core', 'sales', 'procurement', 'packing', 'exports', 'finance', 'people'], 'seat_limit' => 25, 'storage_limit_bytes' => 1000000000, 'monthly_price_ugx' => 250000, 'quarterly_price_ugx' => 700000, 'annual_price_ugx' => 2500000, 'is_public' => false, 'is_active' => true]);
        $sub = BillingSubscription::create(['business_id' => $b->id, 'billing_plan_id' => $plan->id, 'billing_email' => $d->user('owner')->email, 'billing_cycle' => 'monthly', 'price_ugx' => 250000, 'tax_rate' => 0, 'starts_on' => $d->date(1), 'next_invoice_on' => $d->date(1), 'status' => 'pending', 'grace_days' => 7, 'agreement_notes' => $d->note('Agreed example subscription.'), 'terms_snapshot' => app(PlatformBilling::class)->planTerms($plan)]);
        $method = BillingPaymentMethod::create(['label' => 'DEMO payment instructions · training only', 'type' => 'bank', 'provider' => 'Fictional Training Bank', 'account_name' => 'DEMO · No real recipient', 'account_number' => 'DEMO-NOT-A-BANK-ACCOUNT', 'is_active' => true]);
        $invoice = app(PlatformBilling::class)->invoice($sub, $method, ['due_on' => $d->date()], $d->id('superadmin'));
        app(PlatformBilling::class)->pay($invoice, ['billing_payment_method_id' => $method->id, 'amount_ugx' => 250000, 'reference' => 'DEMO-SUBSCRIPTION-PAID', 'received_on' => $d->date(), 'notes' => $d->note('Synthetic subscription receipt.')], $d->id('superadmin'));
        $d->put('subscription', $sub);
        $d->put('billing_invoice', $invoice);
        $application = OnboardingRequest::create(['business_id' => $b->id, 'business_name' => $b->name, 'contact_name' => $d->user('owner')->name, 'contact_email' => $d->user('owner')->email, 'contact_phone' => '+256000000000', 'industry' => 'export', 'business_size' => 'growing', 'requested_plan' => 'to_discuss', 'billing_cycle' => 'to_discuss', 'requirements' => $d->note('Connected market buying, packing and exports.'), 'status' => 'approved', 'reviewed_by' => $d->id('superadmin'), 'reviewed_at' => now(), 'privacy_accepted_at' => now(), 'admin_notes' => $d->note('Demo onboarding approved.')]);
        $d->put('onboarding', $application);
        $other = Business::create(['name' => 'DEMO · Second Exporter (trial)', 'code' => 'DEMO-TRIAL', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton', 'is_active' => true, 'lifecycle_status' => 'onboarding', 'billing_mode' => 'trial', 'trial_ends_on' => today('Africa/Kampala')->addDays(7), 'owner_contact_email' => $d->user('owner')->email]);
        BusinessMembership::create(['business_id' => $other->id, 'user_id' => $d->id('owner'), 'role' => 'owner', 'active' => true]);
        $d->put('other_business', $other);
        app(InviteBusinessUser::class)->send($b, 'new-buyer@demo.boosted.test', 'buyer', $d->id('owner'));
        PlatformCase::create(['business_id' => $b->id, 'kind' => 'support', 'title' => 'DEMO · Calibrate Zebra GK888t', 'details' => $d->note('Inspect 100 × 50 mm output before approving this printer.'), 'status' => 'open', 'priority' => 'normal', 'assigned_to' => $d->id('superadmin'), 'created_by' => $d->id('superadmin'), 'due_at' => now()->addDay(), 'deduplication_key' => 'demo-printer-case']);
        PlatformSupportGrant::create(['business_id' => $b->id, 'platform_user_id' => $d->id('superadmin'), 'scope' => 'diagnostics', 'reason' => $d->note('Owner-authorised, expiring training support.'), 'status' => 'approved', 'approved_by' => $d->id('owner'), 'approved_at' => now(), 'expires_at' => now()->addHours(4)]);
        PlatformLimitOverride::create(['business_id' => $b->id, 'metric' => 'seat_limit', 'allowance' => 30, 'starts_on' => $d->date(), 'ends_on' => $d->date(-30), 'reason' => $d->note('Example dated allowance override.'), 'created_by' => $d->id('superadmin')]);
        PlatformHealthEvidence::create(['business_id' => $b->id, 'metric' => 'backup', 'status' => 'unknown', 'evidence' => $d->note('No production backup or restore verification has been performed.'), 'observed_at' => now(), 'valid_until' => now()->addDay(), 'recorded_by' => $d->id('superadmin')]);
        app(PlatformAccess::class)->audit($b->id, $d->id('superadmin'), 'demo.platform.ready', $d->note('Linked demo platform and business accounts created.'), [], ['subscription_id' => $sub->id]);
    }
}
