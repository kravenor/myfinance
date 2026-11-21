<?php

namespace App\Http\Controllers;

use App\Http\Requests\Invitation\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Throwable;

class InvitationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InvitationResource::collection(
            Invitation::with(['inviter', 'acceptedUser'])->latest('id')->paginate(50)
        );
    }

    /**
     * Il link in chiaro esce solo in questa risposta: dopo resta salvato solo l'hash.
     */
    public function store(StoreInvitationRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $email = $request->validated('email');

        [$invitation, $token] = Invitation::issue($admin, $email);
        $url = Invitation::url($token);
        SecurityLog::record('invitation.created', $admin->id, [
            'invitation_id' => $invitation->id,
            'bound_to_email' => $email !== null,
        ]);

        $emailSent = false;
        if ($email !== null && $request->boolean('send_email', true) && $this->mailerDelivers()) {
            try {
                Notification::route('mail', $invitation->email)
                    ->notify(new InvitationNotification($url, $admin->name, $invitation->expires_at));
                $emailSent = true;
            } catch (Throwable $e) {
                // SMTP giù o non configurato: l'invito resta valido, l'admin copia il link.
                report($e);
            }
        }

        $invitation->load(['inviter', 'acceptedUser']);

        return (new InvitationResource($invitation))
            ->additional(['url' => $url, 'email_sent' => $emailSent])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Con `log` o `array` Laravel non solleva errori ma non recapita nulla: dire «inviata»
     * sarebbe falso, e con `log` il link col token finirebbe in chiaro nel file di log.
     */
    private function mailerDelivers(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Revoca un invito non ancora usato, o toglie dalla lista uno usato o scaduto.
     */
    public function destroy(Invitation $invitation): Response
    {
        $invitation->delete();
        SecurityLog::record('invitation.revoked', null, ['invitation_id' => $invitation->id]);

        return response()->noContent();
    }
}
