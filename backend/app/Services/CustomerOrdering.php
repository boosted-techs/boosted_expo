<?php

namespace App\Services;

use App\Mail\CustomerOrderingMail;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOrderingLink;
use App\Models\Product;
use App\Models\SalesRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOrdering
{
    public function invite(Business $business, Customer $customer, array $products, int $actor): CustomerOrderingLink
    {
        $token = Str::random(64);
        $link = DB::transaction(function () use ($business, $customer, $products, $actor, $token) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $customer = Customer::query()->where('business_id', $business->id)->where('active', true)->findOrFail($customer->id);
            if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['customer' => 'Set a valid customer email before sharing access.']);
            }
            $ids = Product::query()->where('business_id', $business->id)->where('active', true)->whereIn('id', $products)->pluck('id')->map(fn ($i) => (int) $i)->all();
            abort_unless(count($ids) === count($products) && count($ids) > 0, 422, 'Choose valid distinct products for this customer.');
            CustomerOrderingLink::query()->where('business_id', $business->id)->where('customer_id', $customer->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            return CustomerOrderingLink::create(['business_id' => $business->id, 'customer_id' => $customer->id, 'recipient' => strtolower(trim($customer->email)), 'token_hash' => hash('sha256', $token), 'product_ids' => $ids, 'expires_at' => now()->addDays(14), 'created_by' => $actor]);
        }, 3);
        try {
            Mail::to($link->recipient)->send(new CustomerOrderingMail($business->name, $token));
            $link->update(['delivery_status' => config('mail.default') === 'log' ? 'logged' : 'sent']);
        } catch (\Throwable $e) {
            report($e);
            $link->update(['delivery_status' => 'failed', 'revoked_at' => now()]);
        }
        app(PlatformAccess::class)->audit($business->id, $actor, 'customer.ordering_invited', 'Private ordering access requested for registered customer.', [], ['link_id' => $link->id, 'delivery_status' => $link->delivery_status]);

        return $link;
    }

    public function resolve(string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $link = CustomerOrderingLink::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
        $business = Business::query()->where('is_active', true)->findOrFail($link->business_id);
        $customer = Customer::query()->where('business_id', $business->id)->where('active', true)->findOrFail($link->customer_id);
        abort_unless(strtolower(trim((string) $customer->email)) === $link->recipient, 404);

        return compact('link', 'business', 'customer');
    }

    public function submit(string $token, array $data): SalesRequest
    {
        return DB::transaction(function () use ($token, $data) {
            $context = $this->resolve($token);
            $business = $context['business'];
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $context = $this->resolve($token);
            app(PlatformTenancy::class)->assertModuleWrite($business, 'sales');
            foreach ($data['items'] as $item) {
                abort_unless(in_array((int) $item['product_id'], $context['link']->product_ids, true), 404);
            }

            return app(SalesWorkflow::class)->createRequest($business->id, [...$data, 'customer_id' => $context['customer']->id, 'source' => 'customer'], null);
        }, 3);
    }
}
