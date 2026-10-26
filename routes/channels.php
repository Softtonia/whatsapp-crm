<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('shared-inbox', function (User $user) {
    return $user->hasRole('super_admin');
});