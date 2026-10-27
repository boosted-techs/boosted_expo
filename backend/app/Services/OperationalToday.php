<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\MarketTrip;
use App\Models\OrderTask;
use App\Models\PartnerFollowup;
use App\Models\SalesOrder;
use App\Models\StaffPerson;
use App\Models\StaffShift;
use App\Support\BusinessPermissions;

class OperationalToday
{
    public function forMember(BusinessMembership $member): array
    {
        $b = $member->business_id;
        $access = app(BusinessPermissions::class);
        $manager = in_array($member->role, ['owner', 'admin'], true);
        $person = StaffPerson::query()->where('business_id', $b)->where('user_id', $member->user_id)->value('id');
        $tasks = OrderTask::query()->where('business_id', $b)->when(! $manager, fn ($q) => $q->where('staff_person_id', $person ?? 0))->whereNotIn('status', ['completed', 'cancelled'])->with('person', 'order')->orderBy('due_at')->limit(20)->get();
        $orders = $access->allows($member, 'sales.orders.view') ? SalesOrder::query()->where('business_id', $b)->where('status', 'confirmed')->whereDate('required_on', '<=', today('Africa/Kampala'))->with('customer')->orderBy('required_on')->limit(20)->get() : collect();
        $trips = $access->allows($member, 'purchases.view') ? MarketTrip::query()->where('business_id', $b)->where('status', '!=', 'closed')->with('person')->orderBy('due_at')->limit(20)->get() : collect();
        $shifts = StaffShift::query()->where('business_id', $b)->whereNotNull('published_at')->when(! $manager, fn ($q) => $q->where('user_id', $member->user_id))->where('status', 'scheduled')->where('starts_at', '<=', now()->addDay())->with('person', 'user', 'attendances')->orderBy('starts_at')->limit(20)->get();
        $followups = PartnerFollowup::query()->where('business_id', $b)->where('status', '!=', 'resolved')->when(! $manager, fn ($q) => $q->where('staff_person_id', $person ?? 0))->with('person')->orderBy('due_at')->limit(20)->get();

        return compact('followups', 'tasks', 'orders', 'trips', 'shifts');
    }
}
