<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Config extends Model
{
    protected $table = 'config';

    public static function get($key)
    {
    	return Config::where('key', $key)->first()?->value;
    }
}
