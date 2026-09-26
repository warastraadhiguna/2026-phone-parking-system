<?php

namespace App\Support\Logging;

/**
 * Replaces values of sensitive keys (at any depth) and bearer tokens inside strings.
 *
 * Key matching is case-insensitive and ignores "-" vs "_", so "X-Api-Key", "api_key"
 * and "apiKey" are all caught. Prefer over-redaction: a missing log detail is cheap,
 * a leaked credential is not.
 */
final class SensitiveDataRedactor
{
    public const REDACTED = '[REDACTED]';

    /** Exact key names (normalised: lowercase, no separators). */
    private const SENSITIVE_KEYS = [
        'password',
        'passwordconfirmation',
        'currentpassword',
        'newpassword',
        'pin',
        'token',
        'accesstoken',
        'refreshtoken',
        'idtoken',
        'authorization',
        'proxyauthorization',
        'cookie',
        'setcookie',
        'secret',
        'clientsecret',
        'apikey',
        'serverkey',
        'clientkey',
        'privatekey',
        'signature',
        'signaturekey',
        'appkey',
        'credential',
        'credentials',
        'xsrftoken',
        'csrftoken',
        // Personal data
        'identitynumber',
        'nik',
    ];

    /** Key suffixes that are always sensitive (normalised). */
    private const SENSITIVE_SUFFIXES = ['password', 'token', 'secret', 'apikey', 'serverkey', 'privatekey'];

    private const BEARER_PATTERN = '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=|]+/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return [self::REDACTED];
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = $this->redactString($value);
            }
        }

        return $data;
    }

    public function redactString(string $value): string
    {
        return (string) preg_replace(self::BEARER_PATTERN, '$1 '.self::REDACTED, $value);
    }

    public function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower(str_replace(['-', '_', ' '], '', $key));

        if (in_array($normalised, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($normalised, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
