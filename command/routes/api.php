<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AgentAppController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentListController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\CloseoutController;
use App\Http\Controllers\Api\CollationEntryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\IrevEntryController;
use App\Http\Controllers\Api\NavCountsController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PollingUnitController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\ResultController;
use App\Http\Controllers\Api\ResultFlagController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\SmsWebhookController;
use App\Http\Controllers\Api\StipendController;
use App\Http\Controllers\Api\StructureController;
use App\Http\Controllers\Api\TallyController;
use App\Http\Controllers\Api\TribunalController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UssdController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Sign-in
    Route::post('auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Aggregator webhook for results sent by SMS
    Route::post('sms/inbound', SmsWebhookController::class)->middleware('throttle:600,1');

    // Africa's Talking USSD callback; the secret is in the URL because AT can't send a header
    Route::post('ussd/africastalking/{token}', UssdController::class)->middleware('throttle:1200,1');

    // The agent app from a QR code: a signed link that works for an hour without signing in.
    Route::get('agent-app/phone', [AgentAppController::class, 'phone'])->middleware(['signed', 'throttle:30,1'])->name('agent-app.phone');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('me/password', [AccountController::class, 'password'])->middleware('throttle:10,1');
        Route::get('me/notices', [NoticeController::class, 'index']);
        Route::post('me/notices/read', [NoticeController::class, 'read']);
        Route::middleware('throttle:10,1')->group(function () {
            Route::get('me/two-factor', [TwoFactorController::class, 'show']);
            Route::post('me/two-factor', [TwoFactorController::class, 'start']);
            Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('me/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::delete('me/two-factor', [TwoFactorController::class, 'destroy']);
        });

        // Agent app
        Route::middleware('role:agent,collation_agent,ward_supervisor,lga_supervisor')->post('devices', [DeviceController::class, 'store']);
        Route::middleware('role:agent')->group(function () {
            Route::post('check-ins', [CheckInController::class, 'store']);
            Route::post('results', [ResultController::class, 'store']);
            Route::post('results/{result}/photo', [ResultController::class, 'attachPhoto']);
        });
        Route::middleware('role:agent,collation_agent,ward_supervisor,lga_supervisor')->post('incidents', [IncidentController::class, 'store']);

        // Agent onboarding: practice run, quiz, eve-of-election confirmation
        Route::middleware('role:agent,collation_agent,ward_supervisor,lga_supervisor')->group(function () {
            Route::get('onboarding', [OnboardingController::class, 'show']);
            Route::post('onboarding/practice', [OnboardingController::class, 'practice']);
            Route::post('onboarding/quiz', [OnboardingController::class, 'quiz'])->middleware('throttle:20,1');
            Route::post('onboarding/confirm', [OnboardingController::class, 'confirm']);
        });

        // Supervisors
        Route::middleware('role:ward_supervisor,lga_supervisor,situation_room,dg')->group(function () {
            Route::get('check-ins', [CheckInController::class, 'index']);
            Route::post('check-ins/{checkIn}/review', [CheckInController::class, 'review']);
            Route::get('check-ins/{checkIn}/selfie', [CheckInController::class, 'selfie']);
            Route::get('agents', AgentListController::class);
            Route::post('agents/{agent}/assign', [AgentController::class, 'assign']);
            Route::middleware('role:situation_room,dg')->patch('agents/{agent}', [AgentController::class, 'update']);
            Route::patch('polling-units/{unit}', [PollingUnitController::class, 'update']);
        });

        // The agent app, for every command-room role to download and pass on
        Route::middleware('role:dg,situation_room,verifier,data_officer,legal,lga_supervisor,ward_supervisor')->group(function () {
            Route::get('agent-app', [AgentAppController::class, 'show']);
            Route::get('agent-app/download', [AgentAppController::class, 'download']);
        });
        Route::middleware('role:dg,situation_room')->post('agent-app/update', [AgentAppController::class, 'update']);

        // Command room: read
        Route::middleware('role:dg,situation_room,verifier,data_officer,legal,lga_supervisor,ward_supervisor')->group(function () {
            Route::get('tally', TallyController::class);
            Route::get('nav-counts', NavCountsController::class);
            Route::get('units', [UnitController::class, 'index']);
            Route::get('alerts', [AlertController::class, 'index']);
            Route::get('incidents', [IncidentController::class, 'index']);
            Route::get('collation-entries', [CollationEntryController::class, 'index']);
            Route::get('results/{result}', [ResultController::class, 'show']);
            Route::get('results/{result}/photo', [ResultController::class, 'photo']);
            Route::get('structure', [StructureController::class, 'index']);
        });

        // Verification
        Route::middleware('role:verifier,situation_room,dg')->group(function () {
            Route::get('results-queue', [ResultController::class, 'queue']);
            Route::post('results/{result}/approve', [ResultController::class, 'approve']);
            Route::post('results/{result}/send-back', [ResultController::class, 'sendBack']);
        });

        // Flags on results: raised and discussed by reviewers, the situation room, legal and the DG
        Route::middleware('role:verifier,situation_room,dg,legal')->group(function () {
            Route::get('results/{result}/flags', [ResultFlagController::class, 'index']);
            Route::post('results/{result}/flags', [ResultFlagController::class, 'store']);
            Route::post('result-flags/{flag}/comments', [ResultFlagController::class, 'comment']);
            Route::patch('result-flags/{flag}', [ResultFlagController::class, 'update']);
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

        // After the election
        Route::middleware('role:dg,legal,situation_room')->get('closeout', [CloseoutController::class, 'show']);
        Route::middleware('role:dg')->group(function () {
            Route::post('closeout/declare', [CloseoutController::class, 'declare']);
            Route::post('closeout/retention', [CloseoutController::class, 'retention']);
        });
        Route::middleware('role:legal,dg')->group(function () {
            Route::get('tribunal-cases', [TribunalController::class, 'index']);
            Route::post('tribunal-cases', [TribunalController::class, 'store']);
            Route::get('tribunal-cases/{case}', [TribunalController::class, 'show']);
            Route::patch('tribunal-cases/{case}', [TribunalController::class, 'update']);
            Route::post('tribunal-cases/{case}/grounds', [TribunalController::class, 'storeGround']);
            Route::patch('tribunal-grounds/{ground}', [TribunalController::class, 'updateGround']);
            Route::post('tribunal-cases/{case}/tasks', [TribunalController::class, 'storeTask']);
            Route::patch('tribunal-tasks/{task}', [TribunalController::class, 'updateTask']);
            Route::get('tribunal-cases/{case}/bundle', [TribunalController::class, 'bundle'])->middleware('throttle:10,1');
        });

        // Finance (the DG approves; finance prepares and exports)
        Route::middleware('role:finance,dg')->group(function () {
            Route::get('stipends', [StipendController::class, 'index']);
            Route::get('stipends/export', [StipendController::class, 'export']);
        });

        // Setup (before election day)
        Route::middleware('role:dg,situation_room')->group(function () {
            Route::get('setup/structure', [SetupController::class, 'structure']);
            Route::post('structure/import', [StructureController::class, 'import']);
            Route::get('setup/roster', [SetupController::class, 'roster']);
            Route::post('roster/import', [RosterController::class, 'importAgents']);
            Route::post('roster/invite', [RosterController::class, 'invite'])->middleware('throttle:10,1');
            Route::post('roster/auto-assign', [RosterController::class, 'autoAssign']);
            Route::get('setup/supervisors', [SetupController::class, 'supervisors']);
            Route::post('supervisors/import', [RosterController::class, 'importSupervisors']);
            Route::post('supervisors', [RosterController::class, 'storeSupervisor']);
            Route::get('readiness', [ReadinessController::class, 'index']);
            Route::post('readiness/remind', [ReadinessController::class, 'remind'])->middleware('throttle:10,1');
        });

        // Admin (DG)
        Route::middleware('role:dg')->group(function () {
            Route::get('campaign', [CampaignController::class, 'show']);
            Route::patch('campaign', [CampaignController::class, 'update']);
            Route::post('parties', [CampaignController::class, 'storeParty']);
            Route::patch('parties/{party}', [CampaignController::class, 'updateParty']);
            Route::delete('parties/{party}', [CampaignController::class, 'destroyParty']);
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
            Route::post('users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor']);
            Route::get('notification-rules', [NoticeController::class, 'rules']);
            Route::patch('notification-rules/{rule}', [NoticeController::class, 'updateRule']);
            Route::post('notification-rules/test', [NoticeController::class, 'test'])->middleware('throttle:3,1');
        });
    });
});
