<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushSubscription\StorePushSubscriptionRequest;
use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sottoscrizioni Web Push del dispositivo corrente (una per browser, identificata dall'endpoint). */
class PushSubscriptionController extends Controller
{
    public function key(): JsonResponse
    {
        return response()->json(['data' => ['public_key' => config('webpush.vapid.public_key') ?: null]]);
    }

    public function store(StorePushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->updatePushSubscription(
            $request->validated('endpoint'),
            $request->validated('keys.p256dh'),
            $request->validated('keys.auth'),
            $request->validated('content_encoding') ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string', 'max:500']])['endpoint'];

        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($endpoint);

        return response()->noContent();
    }

    public function test(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! config('webpush.vapid.public_key') || ! $user->pushSubscriptions()->exists()) {
            return response()->json(['message' => 'Le notifiche push non sono attive su nessun dispositivo.'], 422);
        }

        $user->notify(new TestPushNotification);

        return response()->json(['message' => 'Notifica di prova inviata.']);
    }
}
