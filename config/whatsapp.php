<?php

return [
    'driver' => env('WA_DRIVER', 'meta'),
    'evolution_url' => env('EVOLUTION_URL', 'http://127.0.0.1:8080'),
    'evolution_key' => env('EVOLUTION_API_KEY'),
    'evolution_instance' => env('EVOLUTION_INSTANCE', 'restaurant'),
    'evolution_webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET'),
    'evolution_webhook_url' => env('EVOLUTION_WEBHOOK_URL'),
];
