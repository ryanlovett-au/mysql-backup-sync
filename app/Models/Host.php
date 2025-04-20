<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Host extends Model
{
    public function databases()
    {
        return $this->hasMany(Database::class)->where('is_active', true);
    }
}
