<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotMenuOption extends Model
{
    protected $fillable = ['menu_id', 'title', 'action_type', 'action_value'];
}
