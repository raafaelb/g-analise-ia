<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', fn ($user, $userId) => (int) $user->id === (int) $userId);
