<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdateNotificationPreferencesRequest;
use App\Models\User;
use App\Notifications\TestEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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

    /** Invio di prova sincrono: dice subito se l'SMTP funziona, senza dettagli tecnici all'utente. */
    public function testEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $notification = new TestEmailNotification;
        $to = $user->routeNotificationForMail($notification);

        if (config('mail.default') === 'log') {
            return response()->json(['message' => 'Il server scrive le email nel log (MAIL_MAILER=log): per ora non arrivano a nessuno.'], 422);
        }

        try {
            $user->notifyNow($notification);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Invio non riuscito: controlla la configurazione email del server (il dettaglio è nel log).'], 422);
        }

        return response()->json(['message' => "Email di prova inviata a {$to}."]);
    }
}
