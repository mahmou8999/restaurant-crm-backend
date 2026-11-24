<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_sessions')) {
            Schema::create('bot_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('active_menu_id')->nullable()->constrained('bot_menus')->nullOnDelete();
                $table->string('state')->default('idle');
                $table->json('context')->nullable();
                $table->timestamps();
                $table->unique('customer_id');
            });
        }

        if (! Schema::hasTable('canned_replies')) {
            Schema::create('canned_replies', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('message');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
