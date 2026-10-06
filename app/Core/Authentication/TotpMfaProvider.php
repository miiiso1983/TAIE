<?php

namespace App\Core\Authentication;

use App\Core\Contracts\MFAProviderInterface;
use App\Models\MfaCredential;
use Illuminate\Support\Facades\Hash;

final class TotpMfaProvider implements MFAProviderInterface
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function verify(int $userId, string $code): bool
    {
        $credential = MfaCredential::query()->where('user_id', $userId)->where('type', 'totp')->whereNotNull('verified_at')->first();
        if (! $credential) {
            return false;
        }
        if ($this->matches($credential->secret_encrypted ?? '', $code)) {
            return true;
        }

        $recoveryCodes = $credential->recovery_codes_hashed ?? [];
        foreach ($recoveryCodes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($recoveryCodes[$index]);
                $credential->forceFill(['recovery_codes_hashed' => array_values($recoveryCodes)])->save();

                return true;
            }
        }

        return false;
    }

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function matches(string $secret, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return false;
        }
        $counter = intdiv(time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            $binary = pack('N2', 0, $counter + $offset);
            $hash = hash_hmac('sha1', $binary, $key, true);
            $position = ord($hash[19]) & 0x0F;
            $number = ((ord($hash[$position]) & 0x7F) << 24) | ((ord($hash[$position + 1]) & 0xFF) << 16) | ((ord($hash[$position + 2]) & 0xFF) << 8) | (ord($hash[$position + 3]) & 0xFF);
            if (hash_equals(str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT), $code)) {
                return true;
            }
        }

        return false;
    }

    private function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($value, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        return $binary;
    }
}
