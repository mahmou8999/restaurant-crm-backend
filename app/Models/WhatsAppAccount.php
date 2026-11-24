<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAccount extends Model
{
    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'name', 'phone_number_id', 'access_token', 'business_account_id',
        'webhook_verify_token', 'app_secret', 'is_active', 'driver',
        'instance_name', 'session_status', 'last_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
        ];
    }
}
