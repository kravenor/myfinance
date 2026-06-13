<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdateNotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $user->notificationPreferences()]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->notification_preferences = array_merge(
            $user->notificationPreferences(),
            $request->validated(),
        );
        $user->save();

        return response()->json(['data' => $user->notificationPreferences()]);
    }
}
