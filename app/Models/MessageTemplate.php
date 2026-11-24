<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = ['name', 'language', 'category', 'meta_status', 'components', 'body'];

    protected function casts(): array
    {
        return ['components' => 'array'];
    }
}
