<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\CollationEntryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\IrevEntryController;
use App\Http\Controllers\Api\ResultController;
use App\Http\Controllers\Api\SmsWebhookController;
use App\Http\Controllers\Api\StipendController;
use App\Http\Controllers\Api\StructureController;
use App\Http\Controllers\Api\TallyController;
use App\Http\Controllers\Api\UnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Sign-in
    Route::post('auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Aggregator webhook for results sent by SMS
    Route::post('sms/inbound', SmsWebhookController::class)->middleware('throttle:600,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Agent app
        Route::middleware('role:agent,collation_agent,ward_supervisor,lga_supervisor')->post('devices', [DeviceController::class, 'store']);
        Route::middleware('role:agent')->group(function () {
            Route::post('check-ins', [CheckInController::class, 'store']);
            Route::post('results', [ResultController::class, 'store']);
            Route::post('results/{result}/photo', [ResultController::class, 'attachPhoto']);
        });
        Route::middleware('role:agent,collation_agent,ward_supervisor,lga_supervisor')->post('incidents', [IncidentController::class, 'store']);

        // Supervisors
        Route::middleware('role:ward_supervisor,lga_supervisor,situation_room,dg')->group(function () {
            Route::post('check-ins/{checkIn}/review', [CheckInController::class, 'review']);
            Route::post('agents/{agent}/assign', [AgentController::class, 'assign']);
        });

        // Command room: read
        Route::middleware('role:dg,situation_room,verifier,data_officer,legal,lga_supervisor,ward_supervisor')->group(function () {
            Route::get('tally', TallyController::class);
            Route::get('units', [UnitController::class, 'index']);
            Route::get('alerts', [AlertController::class, 'index']);
            Route::get('incidents', [IncidentController::class, 'index']);
            Route::get('collation-entries', [CollationEntryController::class, 'index']);
            Route::get('results/{result}', [ResultController::class, 'show']);
        });

        // Verification
        Route::middleware('role:verifier,situation_room,dg')->group(function () {
            Route::get('results-queue', [ResultController::class, 'queue']);
            Route::post('results/{result}/approve', [ResultController::class, 'approve']);
            Route::post('results/{result}/send-back', [ResultController::class, 'sendBack']);
        });

        // Data entry
        Route::middleware('role:data_officer,collation_agent,situation_room,dg')->post('collation-entries', [CollationEntryController::class, 'store']);
        Route::middleware('role:data_officer,situation_room,dg')->post('irev-entries', [IrevEntryController::class, 'store']);

        // Situation room
        Route::middleware('role:situation_room,dg,legal')->group(function () {
            Route::patch('alerts/{alert}', [AlertController::class, 'update']);
            Route::patch('incidents/{incident}', [IncidentController::class, 'update']);
        });

        // Legal
        Route::middleware('role:legal,dg')->group(function () {
            Route::get('units/{unit}/evidence', [UnitController::class, 'evidence']);
            Route::get('audit-logs', [AuditController::class, 'index']);
            Route::get('audit-logs/verify', [AuditController::class, 'verify']);
        });

        // Finance (the DG approves; finance prepares and exports)
        Route::middleware('role:finance,dg')->group(function () {
            Route::get('stipends', [StipendController::class, 'index']);
            Route::get('stipends/export', [StipendController::class, 'export']);
        });

        // Setup
        Route::middleware('role:dg,situation_room')->post('structure/import', [StructureController::class, 'import']);
    });
});
