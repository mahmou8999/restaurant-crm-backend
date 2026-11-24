<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationAssignment extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }
}
