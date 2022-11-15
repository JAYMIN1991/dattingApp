<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Device_token extends Model
{
    protected $fillable = [
        'device_token',
        'user_id'
    ];

    public function user()
    {
        return $this->belongsTo('App\User', 'device_token');
    }
}
