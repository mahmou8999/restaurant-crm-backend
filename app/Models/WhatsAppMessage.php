<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'customer_id', 'campaign_id', 'wamid', 'direction', 'status', 'body',
        'meta_payload', 'provider',
    ];

    protected function casts(): array
    {
        return ['meta_payload' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function recordOutbound(array $attributes): self
    {
        $wamid = $attributes['wamid'] ?? null;
        if (is_string($wamid) && $wamid !== '') {
            return static::firstOrCreate(['wamid' => $wamid], $attributes);
        }

        return static::create($attributes);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'message_id');
    }
}
