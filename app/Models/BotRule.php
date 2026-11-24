<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotRule extends Model
{
    protected $fillable = ['trigger_type', 'trigger_value', 'response', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
