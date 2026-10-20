<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrIdentity extends Model
{
    protected $fillable = ['business_id','level','record_id','public_token','public_enabled'];

    protected function casts(): array { return ['public_enabled'=>'boolean']; }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}