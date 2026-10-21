<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public $timestamps = false;

    protected $fillable = ['setting_group', 'setting_key', 'setting_value', 'value_type', 'description', 'updated_by'];

    protected $primaryKey = 'id';
}
