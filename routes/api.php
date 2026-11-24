<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EvolutionWebhookController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::get('webhooks/whatsapp', [WebhookController::class, 'verify']);
    Route::post('webhooks/whatsapp', [WebhookController::class, 'receive']);
    Route::post('webhooks/evolution', [EvolutionWebhookController::class, 'receive']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('reports', [ReportsController::class, 'index']);
        Route::get('reports/campaigns', [ReportsController::class, 'campaigns']);

        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);

        Route::get('customers/export', [CustomerController::class, 'export']);
        Route::post('customers/import', [CustomerController::class, 'import']);
        Route::apiResource('customers', CustomerController::class);
        Route::get('customers/{customer}/messages', [CustomerController::class, 'messages']);

        Route::apiResource('groups', GroupController::class)->parameters(['groups' => 'group']);
        Route::post('groups/{group}/customers', [GroupController::class, 'addCustomers']);

        Route::apiResource('campaigns', CampaignController::class);
        Route::post('campaigns/{campaign}/preview', [CampaignController::class, 'preview']);
        Route::post('campaigns/{campaign}/test', [CampaignController::class, 'test']);
        Route::post('campaigns/{campaign}/start', [CampaignController::class, 'start']);
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
        Route::get('campaigns/{campaign}/report', [CampaignController::class, 'report']);

        Route::get('whatsapp/status', [WhatsAppController::class, 'status']);
        Route::get('whatsapp/templates', [WhatsAppController::class, 'templates']);
        Route::post('whatsapp/templates', [WhatsAppController::class, 'storeTemplate']);
        Route::put('whatsapp/templates/{template}', [WhatsAppController::class, 'updateTemplate']);
        Route::post('whatsapp/templates/sync', [WhatsAppController::class, 'syncTemplates']);
        Route::post('whatsapp/send', [WhatsAppController::class, 'send']);
        Route::post('whatsapp/evolution/connect', [WhatsAppController::class, 'evolutionConnect']);
        Route::get('whatsapp/evolution/status', [WhatsAppController::class, 'evolutionStatus']);
        Route::post('whatsapp/evolution/disconnect', [WhatsAppController::class, 'evolutionDisconnect']);
        Route::get('whatsapp/conversations', [ConversationController::class, 'index']);
        Route::get('whatsapp/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('whatsapp/conversations/{conversation}/reply', [ConversationController::class, 'reply']);
        Route::post('whatsapp/conversations/{conversation}/assign', [ConversationController::class, 'assign']);
        Route::post('whatsapp/conversations/{conversation}/close', [ConversationController::class, 'close']);

        Route::get('bot/rules', [AutomationController::class, 'rulesIndex']);
        Route::post('bot/rules', [AutomationController::class, 'rulesStore']);
        Route::put('bot/rules/{rule}', [AutomationController::class, 'rulesUpdate']);
        Route::get('bot/menus', [AutomationController::class, 'menusIndex']);
        Route::post('bot/menus', [AutomationController::class, 'menusStore']);
        Route::post('bot/menus/{menu}/options', [AutomationController::class, 'menuOptionStore']);

        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::get('roles', [UserController::class, 'roles']);

        Route::get('settings', [SettingsController::class, 'index']);
        Route::put('settings', [SettingsController::class, 'update']);
        Route::get('canned-replies', [SettingsController::class, 'cannedReplies']);
        Route::post('canned-replies', [SettingsController::class, 'storeCannedReply']);
        Route::put('canned-replies/{cannedReply}', [SettingsController::class, 'updateCannedReply']);
        Route::delete('canned-replies/{cannedReply}', [SettingsController::class, 'destroyCannedReply']);
    });
});
