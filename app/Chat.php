<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chat extends Model
{
    use SoftDeletes;

    public $fillable = ['sender_id', 'receiver_id', 'message', 'is_read', 'created_at'];
}
