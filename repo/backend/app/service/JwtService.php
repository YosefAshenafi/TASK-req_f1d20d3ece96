<?php
declare(strict_types=1);
namespace app\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private static string $algo = 'HS256';

    public static function issue(int $userId, string $role, string $username): string
    {
        $secret = self::secret();
        $now    = time();

        $payload = [
            'iss'      => 'campus-portal',
            'sub'      => (string)$userId,
            'role'     => $role,
            'username' => $username,
            'iat'      => $now,
            'exp'      => $now + 86400 * 7, // 7 days
        ];

        return JWT::encode($payload, $secret, self::$algo);
    }

    public static function verify(string $token): object
    {
        return JWT::decode($token, new Key(self::secret(), self::$algo));
    }

    private static function secret(): string
    {
        $secret = env('JWT_SECRET', '');
        if (empty($secret)) {
            throw new \RuntimeException('JWT_SECRET env var not set');
        }
        return $secret;
    }
}
