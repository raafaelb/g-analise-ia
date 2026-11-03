<?php

use App\Http\Controllers\AgentInferenceController;
use App\Http\Middleware\EnsureAgentInferenceGateway;
use Illuminate\Support\Facades\Route;

Route::post('internal/inference/v1/responses', AgentInferenceController::class)
    ->middleware(EnsureAgentInferenceGateway::class)
    ->name('agent.inference.responses');
