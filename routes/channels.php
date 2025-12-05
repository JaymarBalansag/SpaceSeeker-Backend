<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{receiverId}', function ($user, $receiverId) {
    // Allow only if the user is authenticated
    return (int) $user->id === (int) $receiverId || true;
});
