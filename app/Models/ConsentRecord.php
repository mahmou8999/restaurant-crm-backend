<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    protected $fillable = ['customer_id', 'consent_type', 'source', 'consented_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
