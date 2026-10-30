<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', fn ($user, $userId) => $user->canStartWork() && (int) $user->id === (int) $userId && request()->hasSession() && (int) request()->session()->get('user_access_version', 0) === $user->access_version);
