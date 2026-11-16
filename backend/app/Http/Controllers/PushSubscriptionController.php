<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushSubscription\StorePushSubscriptionRequest;
use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

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

    /** Prova solo sul dispositivo che la chiede: le altre iscrizioni dell'utente non suonano. */
    public function test(Request $request): JsonResponse
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string', 'max:500']])['endpoint'];

        /** @var User $user */
        $user = $request->user();
        $subscriptions = $user->pushSubscriptions()->where('endpoint', $endpoint)->get();

        if (! config('webpush.vapid.public_key') || $subscriptions->isEmpty()) {
            return response()->json(['message' => 'Le notifiche push non sono attive su questo dispositivo.'], 422);
        }

        Notification::route('WebPush', $subscriptions)->notify(new TestPushNotification);

        return response()->json(['message' => 'Notifica di prova inviata.']);
    }
}
