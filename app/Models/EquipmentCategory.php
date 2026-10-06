<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentCategory extends Model
{
    protected $fillable = ['name'];

    public function machineCount(): int
    {
        return Equipment::where('category', $this->name)->count();
    }
}