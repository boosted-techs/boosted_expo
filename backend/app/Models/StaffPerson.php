<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPerson extends Model
{
    protected $table = 'staff_people';

    protected $fillable = ['business_id', 'user_id', 'staff_code', 'name', 'kind', 'skills', 'phone', 'active'];

    protected function casts(): array
    {
        return ['skills' => 'array', 'active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
