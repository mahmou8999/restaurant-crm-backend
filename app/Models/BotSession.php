<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSession extends Model
{
    protected $fillable = ['customer_id', 'active_menu_id', 'state', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activeMenu(): BelongsTo
    {
        return $this->belongsTo(BotMenu::class, 'active_menu_id');
    }
}
