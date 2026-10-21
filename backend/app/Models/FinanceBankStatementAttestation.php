<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceBankStatementAttestation extends Model {
    protected $fillable=[
        'business_id','statement_id','bank_profile_id','issuer_id','issuer_key_id',
        'statement_reference','public_key_sha256','payload_sha256','signature_sha256',
        'signed_payload','signature_base64','signed_at','verified_at','verified_by',
    ];
    protected function casts():array{return [
        'signed_at'=>'datetime','verified_at'=>'datetime',
        'signed_payload'=>'encrypted','signature_base64'=>'encrypted',
    ];}
}