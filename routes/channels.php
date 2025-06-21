<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\MultiplayerRoom;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Private channel for user-specific notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Presence channel for global lobby updates  
Broadcast::channel('presence.global-lobby', function ($user) {
    return [
        'id' => $user->id,
        'username' => $user->username ?? $user->name,
        'avatar' => $user->avatar ?? null,
    ];
});

// Debug test channel (always allow authenticated users)
Broadcast::channel('test.debug', function ($user) {
    \Log::info('Debug channel auth called', [
        'user_id' => $user->id,
        'user_name' => $user->name
    ]);
    return true;
});

// Private channel for Crazy card game rooms
Broadcast::channel('crazy.room.{roomId}', function ($user, $roomId) {
    \Log::info('Crazy room channel auth called', [
        'user_id' => $user->id,
        'room_id' => $roomId
    ]);
    
    $room = MultiplayerRoom::where('room_code', $roomId)->orWhere('id', $roomId)->first();
    
    if (!$room) {
        \Log::warning('Room not found', ['room_id' => $roomId]);
        return false;
    }
    
    // User must be a participant in the room
    $participant = $room->participants()->where('user_id', $user->id)->first();
    if (!$participant) {
        \Log::warning('User not participant in room', [
            'user_id' => $user->id,
            'room_id' => $roomId
        ]);
        return false;
    }
    
    \Log::info('Crazy room channel auth success', [
        'user_id' => $user->id,
        'room_id' => $roomId
    ]);
    return true;
});
    
// Private channel for Codebreaker game rooms
Broadcast::channel('codebreaker.room.{roomId}', function ($user, $roomId) {
    $room = MultiplayerRoom::where('room_code', $roomId)->orWhere('id', $roomId)->first();
    
    if (!$room) {
        return false;
    }
    
    // User must be a participant in the room
    $participant = $room->participants()->where('user_id', $user->id)->first();
    if (!$participant) {
        return false;
    }
    
    return true;
});

// Private channel for chat messages (reusable for all games)
Broadcast::channel('chat.room.{roomId}', function ($user, $roomId) {
    $room = MultiplayerRoom::where('room_code', $roomId)->orWhere('id', $roomId)->first();
    
    if (!$room) {
        return false;
    }
    
    // User must be a participant in the room
    $participant = $room->participants()->where('user_id', $user->id)->first();
    if (!$participant) {
        return false;
    }
    
    return true;
}); 

// Public channels don't need authorization - they're automatically available 