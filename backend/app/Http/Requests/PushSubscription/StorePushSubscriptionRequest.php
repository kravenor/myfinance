<?php

namespace App\Http\Requests\PushSubscription;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', 'starts_with:https://', $this->knownPushService(...)],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ];
    }

    /** Solo servizi push noti: il server invierà richieste a questo URL (niente SSRF). */
    private function knownPushService(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

        foreach ((array) config('finance.notifications.push_hosts') as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return;
            }
        }

        $fail('Servizio di notifiche push non riconosciuto.');
    }
}
