<?php
declare(strict_types=1);

namespace app\service;

use app\exception\AppException;

class EncryptionService
{
    private const CIPHER = 'AES-256-CBC';
    private const IV_LEN = 16;
    private const MASK   = '***';

    private static function key(): string
    {
        $k = getenv('ENCRYPTION_KEY');
        if (!$k) {
            throw new AppException('ENCRYPTION_KEY not configured');
        }
        // key must be 32 bytes; hex-encoded 64-char string -> pack to 32 bytes
        return strlen($k) === 64 ? pack('H*', $k) : substr(str_pad($k, 32, "\0"), 0, 32);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv         = random_bytes(self::IV_LEN);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new AppException('Encryption failed');
        }
        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::IV_LEN) {
            throw new AppException('Invalid ciphertext');
        }
        $iv         = substr($raw, 0, self::IV_LEN);
        $ciphertext = substr($raw, self::IV_LEN);
        $plaintext  = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new AppException('Decryption failed');
        }
        return $plaintext;
    }

    /** Returns masked string for UI display; shows last 4 chars if length >= 8. */
    public static function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::MASK;
        }
        if (strlen($value) >= 8) {
            return self::MASK . substr($value, -4);
        }
        return self::MASK;
    }
}
