<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) e codici di recupero. Il segreto vale solo dopo confirm():
 * un'attivazione lasciata a metà non chiede il codice al login.
 */
class TwoFactorAuthenticator
{
    public const RECOVERY_CODES = 8;

    // ±1 intervallo da 30 s di tolleranza sull'orologio del telefono.
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function enable(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        $url = $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url);

        return ['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => $svg];
    }

    /**
     * Un codice già accettato non vale una seconda volta (timestep salvato).
     */
    public function verify(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($user->two_factor_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_timestep ?? 0, self::WINDOW);
        if ($timestep === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_timestep' => $timestep])->save();

        return true;
    }

    /**
     * @return list<string>|null codici di recupero in chiaro, null se il codice è sbagliato
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $this->verify($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $user->forceFill(['two_factor_recovery_codes' => array_map(self::hash(...), $codes)])->save();

        return $codes;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = self::hash(Str::lower(trim($code)));
        $remaining = $user->two_factor_recovery_codes ?? [];

        foreach ($remaining as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($remaining[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();
    }

    // Codici casuali ad alta entropia: sha256 basta, bcrypt costerebbe 8 verifiche lente a ogni uso.
    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
