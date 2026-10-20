<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FinanceJournal extends Model {
 protected $fillable=['business_id','reference','source_type','source_id','posted_on','currency','base_currency',
 'rate_to_base','description','created_by','approved_by'];
 protected function casts():array{return ['posted_on'=>'date'];}
 public function lines():HasMany{return $this->hasMany(FinanceJournalLine::class,'journal_id');}
}