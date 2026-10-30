<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BiCredentialController;
use App\Http\Controllers\BiDeskController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\SuiteAuthController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\WorkspaceController;
use App\Services\BiDesks;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'home'])->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/auth/suite', [SuiteAuthController::class, 'login'])->middleware('throttle:login')->name('suite.login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
});
Route::get('/auth/openai', [ConnectionController::class, 'redirect'])->middleware('throttle:openai-login')->name('openai.redirect');
Route::get('/auth/openai/callback', [ConnectionController::class, 'callback'])->name('openai.callback');
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/app/{section?}', [WorkspaceController::class, 'page'])->where('section', '.*')->name('workspace');
    Route::prefix('api')->middleware('throttle:workspace')->group(function () {
        Route::get('/users', [UserAccessController::class, 'index'])->name('users.index');
        Route::post('/users', [UserAccessController::class, 'store'])->middleware('throttle:bi-actions')->name('users.store');
        Route::patch('/users/{user}', [UserAccessController::class, 'update'])->whereNumber('user')->middleware('throttle:bi-actions')->name('users.update');
        Route::patch('/account/password', [UserAccessController::class, 'password'])->middleware('throttle:login')->name('account.password');
        Route::get('/catalog/{tenant}/desks/{kind}/{id}/configuration', [BiDeskController::class, 'describe'])->whereNumber(['tenant', 'id'])->whereIn('kind', BiDesks::KINDS)->middleware('throttle:bi-inspect');
        Route::post('/catalog/{tenant}/desks/{kind}/actions', [BiDeskController::class, 'prepare'])->whereNumber('tenant')->whereIn('kind', BiDesks::KINDS)->middleware('throttle:bi-actions');
        Route::get('/catalog/{tenant}/credentials/options', [BiCredentialController::class, 'options'])->whereNumber('tenant')->middleware('throttle:bi-inspect');
        Route::get('/catalog/{tenant}/credentials/{id}/configuration', [BiCredentialController::class, 'describe'])->whereNumber(['tenant', 'id'])->middleware('throttle:bi-inspect');
        Route::post('/catalog/{tenant}/credentials/actions', [BiCredentialController::class, 'prepare'])->whereNumber('tenant')->middleware('throttle:bi-actions');
        Route::post('/catalog/{tenant}/credentials/{id}/inspect/{action}', [BiCredentialController::class, 'inspect'])->whereNumber(['tenant', 'id'])->whereIn('action', ['test', 'schema'])->middleware('throttle:bi-inspect');
        Route::post('/catalog/{tenant}/apps/{id}/actions', [CatalogController::class, 'prepareAction'])->whereNumber(['tenant', 'id'])->middleware('throttle:bi-actions');
        Route::get('/bi-actions/{id}', [CatalogController::class, 'actionStatus'])->whereUuid('id');
        Route::post('/approvals/{id}/publication/verify', [CatalogController::class, 'reconcilePublication'])->whereUuid('id')->middleware('throttle:bi-inspect');
        Route::get('/catalog/{tenant}', [CatalogController::class, 'snapshot'])->whereNumber('tenant')->middleware('throttle:catalog');
        Route::get('/catalog/{tenant}/variables/{id}', [CatalogController::class, 'variable'])->whereNumber('tenant')->where('id', '[a-f0-9]+');
        Route::get('/catalog/{tenant}/resources/{kind}/{id}/inspect/{action}', [CatalogController::class, 'inspect'])->whereNumber(['tenant', 'id'])->whereIn('action', ['data', 'status', 'history', 'health', 'events'])->middleware('throttle:bi-inspect');
        Route::get('/catalog/{tenant}/resources/{kind}/{id}', [CatalogController::class, 'detail'])->whereNumber(['tenant', 'id']);
        Route::post('/catalog/{tenant}/apps/{id}/viewer', [CatalogController::class, 'viewer'])->whereNumber(['tenant', 'id'])->middleware('throttle:bi-viewer');
        Route::get('/catalog/{tenant}/apps/{id}/image', [CatalogController::class, 'image'])->whereNumber(['tenant', 'id'])->middleware('throttle:bi-images');
        Route::get('/catalog/{tenant}/apps/{id}/refresh-coverage', [CatalogController::class, 'coverage'])->whereNumber(['tenant', 'id'])->middleware('throttle:coverage');
        Route::get('/catalog/{tenant}/apps/{id}/preview', [CatalogController::class, 'preview'])->whereNumber(['tenant', 'id']);
        Route::post('/catalog/{tenant}/projects', [CatalogController::class, 'project'])->whereNumber('tenant');
        Route::get('/bootstrap', [WorkspaceController::class, 'bootstrap']);
        Route::post('/projects', [WorkspaceController::class, 'createProject']);
        Route::patch('/projects/{id}', [WorkspaceController::class, 'updateProject']);
        Route::post('/projects/{id}/sync-resource', [CatalogController::class, 'syncProject'])->whereUuid('id')->middleware('throttle:bi-inspect');
        Route::post('/projects/{id}/conversations', [WorkspaceController::class, 'createConversation']);
        Route::get('/conversations/{id}', [WorkspaceController::class, 'conversation']);
        Route::post('/conversations/{id}/messages', [WorkspaceController::class, 'message'])->middleware('throttle:agent-messages');
        Route::post('/runs/{id}/cancel', [WorkspaceController::class, 'cancel']);
        Route::post('/approvals/{id}/decide', [WorkspaceController::class, 'decide']);
        Route::get('/projects/{id}/preview-context', [WorkspaceController::class, 'previewContext'])->middleware('throttle:bi-inspect');
        Route::post('/projects/{id}/preview', [WorkspaceController::class, 'renderPreview'])->middleware('throttle:bi-images');
        Route::get('/projects/{id}/refresh-coverage', [WorkspaceController::class, 'refreshCoverage'])->middleware('throttle:coverage');
        Route::post('/projects/{id}/files', [WorkspaceController::class, 'upload'])->middleware('throttle:attachments');
        Route::post('/artifacts/{id}/visual-consent', [WorkspaceController::class, 'visualConsent']);
        Route::get('/artifacts/{id}/download', [WorkspaceController::class, 'download']);
        Route::post('/schedules', [WorkspaceController::class, 'schedule']);
        Route::patch('/profile', [WorkspaceController::class, 'profile']);
        Route::post('/connections/openai/verify', [ConnectionController::class, 'verifyOpenAi'])->middleware('throttle:openai-verify');
        Route::get('/connections/openai/usage', [ConnectionController::class, 'usage']);
        Route::get('/connections/openai/models', [ConnectionController::class, 'models']);
        Route::post('/connections/horus/login', [ConnectionController::class, 'horusLogin'])->middleware('throttle:bi-login');
        Route::post('/connections/horus/refresh', [ConnectionController::class, 'refreshHorus']);
        Route::delete('/connections/{provider}', [ConnectionController::class, 'disconnect'])->whereIn('provider', ['openai', 'horus']);
    });
});
