<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('driver')->nullable();
            $table->string('instance_name')->nullable();
            $table->string('session_status')->default('disconnected');
            $table->timestamp('last_connected_at')->nullable();
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('provider')->default('meta');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->text('body')->nullable();
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->timestamp('available_at')->nullable();
        });

        DB::table('system_settings')->insertOrIgnore([
            ['key' => 'sender_driver', 'value' => config('whatsapp.driver', 'meta')],
            ['key' => 'daily_limit', 'value' => '40'],
            ['key' => 'delay_min', 'value' => '30'],
            ['key' => 'delay_max', 'value' => '60'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', ['sender_driver', 'daily_limit', 'delay_min', 'delay_max'])
            ->delete();

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('available_at');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('body');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('provider');
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['driver', 'instance_name', 'session_status', 'last_connected_at']);
        });
    }
};
