<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Database extends Model
{
    public function tables()
    {
        return $this->hasMany(Table::class)->where('is_active', true);
    }

    public function alltables()
    {
        return $this->hasMany(Table::class)->orderBy('table_name');
    }
}
