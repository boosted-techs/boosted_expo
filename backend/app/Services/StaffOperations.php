<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceEvent;
use App\Models\StaffShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffOperations
{
    private function member(int $businessId,int $userId):BusinessMembership
    {
        return BusinessMembership::query()->where('business_id',$businessId)
            ->where('user_id',$userId)->where('active',true)->firstOrFail();
    }

    public function schedule(Business $business,array $data,int $actor):StaffShift
    {
        return DB::transaction(function()use($business,$data,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->member($business->id,(int)$data['user_id']);
            $start=Carbon::createFromFormat('!Y-m-d H:i',str_replace('T',' ',$data['starts_at']),$business->timezone)->utc();
            $end=Carbon::createFromFormat('!Y-m-d H:i',str_replace('T',' ',$data['ends_at']),$business->timezone)->utc();
            if(!$end->isAfter($start) || $start->diffInHours($end)>18
                || $start->isBefore(now()->subDay()) || $start->isAfter(now()->addDays(90))) {
                throw ValidationException::withMessages(['ends_at'=>
                    'Shifts must be future or recent, no more than 90 days ahead, and no longer than 18 hours.']);
            }
            if(StaffShift::query()->where('business_id',$business->id)
                ->where('user_id',$data['user_id'])->where('status','!=','cancelled')
                ->where('starts_at','<',$end)->where('ends_at','>',$start)->exists()){
                throw ValidationException::withMessages(['starts_at'=>
                    'This employee already has an overlapping active shift.']);
            }
            return StaffShift::create([
                'business_id'=>$business->id,'user_id'=>$data['user_id'],
                'starts_at'=>$start,'ends_at'=>$end,
                'location'=>$data['location']??null,'notes'=>$data['notes']??null,
                'status'=>'scheduled','created_by'=>$actor,
            ]);
        },3);
    }

    public function cancel(Business $business,int $id,int $actor,string $reason):void
    {
        DB::transaction(function()use($business,$id,$actor,$reason){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $shift=StaffShift::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($id);
            if($shift->status!=='scheduled' || $shift->attendances()->exists()) {
                throw ValidationException::withMessages(['shift'=>
                    'Only unused scheduled shifts can be cancelled. Attendance records remain auditable.']);
            }
            $shift->update([
                'status'=>'cancelled','cancelled_by'=>$actor,'cancel_reason'=>$reason,
            ]);
        },3);
    }

    public function clockIn(Business $business,int $userId,?int $shiftId):StaffAttendance
    {
        return DB::transaction(function()use($business,$userId,$shiftId){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->member($business->id,$userId);
            if(StaffAttendance::query()->where('business_id',$business->id)
                ->where('user_id',$userId)->whereNull('clocked_out_at')->exists()){
                throw ValidationException::withMessages(['attendance'=>
                    'You are already clocked in. Complete your current attendance before starting another.']);
            }
            $shift=null;
            if($shiftId!==null){
                $shift=StaffShift::query()->where('business_id',$business->id)
                    ->where('user_id',$userId)->lockForUpdate()->findOrFail($shiftId);
                if($shift->status!=='scheduled' || $shift->attendances()->exists()
                    || now()->isBefore($shift->starts_at->copy()->subHours(2))
                    || now()->isAfter($shift->ends_at->copy()->addHours(4))){
                    throw ValidationException::withMessages(['shift_id'=>
                        'This scheduled shift is unavailable for check-in at the present time.']);
                }
            }
            $entry=StaffAttendance::create([
                'business_id'=>$business->id,'user_id'=>$userId,
                'staff_shift_id'=>$shift?->id,'clocked_in_at'=>now(),
            ]);
            StaffAttendanceEvent::create([
                'business_id'=>$business->id,'attendance_id'=>$entry->id,
                'actor_id'=>$userId,'action'=>'clock_in','created_at'=>now(),
            ]);
            return $entry;
        },3);
    }

    public function clockOut(Business $business,int $userId,int $id,?string $note):StaffAttendance
    {
        return DB::transaction(function()use($business,$userId,$id,$note){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->member($business->id,$userId);
            $entry=StaffAttendance::query()->where('business_id',$business->id)
                ->where('user_id',$userId)->lockForUpdate()->findOrFail($id);
            if($entry->clocked_out_at!==null){
                throw ValidationException::withMessages(['attendance'=>
                    'This attendance has already been clocked out.']);
            }
            $entry->update(['clocked_out_at'=>now(),'work_note'=>$note]);
            if($entry->staff_shift_id){
                StaffShift::query()->where('business_id',$business->id)
                    ->whereKey($entry->staff_shift_id)->update(['status'=>'completed']);
            }
            StaffAttendanceEvent::create([
                'business_id'=>$business->id,'attendance_id'=>$entry->id,
                'actor_id'=>$userId,'action'=>'clock_out',
                'detail'=>$note,'created_at'=>now(),
            ]);
            return $entry;
        },3);
    }
}
