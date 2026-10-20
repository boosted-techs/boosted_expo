<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceJournalLine extends Model {
 protected $fillable=['business_id','journal_id','account_id','debit','credit','base_debit','base_credit','memo'];
 public function account():BelongsTo{return $this->belongsTo(FinanceAccount::class,'account_id');}
}