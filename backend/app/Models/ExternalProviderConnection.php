<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalProviderConnection extends Model
{
    protected $fillable=['business_id','kind','provider_code','provider_account',
        'signing_secret','trust_reference','active'];
    protected $hidden=['signing_secret'];
    protected function casts():array{return ['signing_secret'=>'encrypted','active'=>'boolean'];}
}
