<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\OrderTask;
use App\Models\Product;
use App\Models\StaffPerson;
use App\Models\StaffShift;
use App\Models\Supplier;
use App\Services\PlatformAccess;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordArchiveController extends Controller
{
    public function update(Request $request, string $type, int $record, string $action): RedirectResponse|JsonResponse
    {
        $types = ['product' => [Product::class, 'catalogue.manage'], 'customer' => [Customer::class, 'customers.manage'], 'supplier' => [Supplier::class, 'suppliers.manage'], 'person' => [StaffPerson::class, 'staff.manage']];
        abort_unless(isset($types[$type]) && in_array($action, ['archive', 'restore'], true), 404);
        [$model,$permission] = $types[$type];
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $business = $request->attributes->get('activeBusiness');
        DB::transaction(function () use ($model, $record, $action, $business, $request, $data, $type): void {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $item = $model::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($record);
            if ($type === 'person' && $action === 'archive') {
                abort_if(StaffShift::query()->where('business_id', $business->id)->where('staff_person_id', $item->id)->where('status', 'scheduled')->where('ends_at', '>', now())->exists() || OrderTask::query()->where('business_id', $business->id)->where('staff_person_id', $item->id)->whereNotIn('status', ['completed', 'cancelled'])->exists(), 422, 'Reassign open tasks and upcoming shifts before archiving this worker.');
            }
            $item->update(['active' => $action === 'restore']);
            app(PlatformAccess::class)->audit($business->id, $request->user()->id, $type.'.'.$action, $data['reason'], [], ['record_id' => $item->id]);
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Record updated. Refresh the directory when you are ready.']);
        }

        return back()->with('status', $action === 'archive' ? 'Record archived. Existing transaction history is retained.' : 'Record restored.');
    }
}
