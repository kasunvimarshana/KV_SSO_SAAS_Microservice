<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class JwtService
{
    private string $secret;
    private int $ttl; // minutes

    public function __construct()
    {
        $this->secret = config('jwt.secret');
        $this->ttl    = (int) config('jwt.ttl', 1440);
    }

    public function generateToken(User $user): string
    {
        $now = time();
        $payload = [
            'iss'         => config('app.name', 'AuthService'),
            'iat'         => $now,
            'exp'         => $now + ($this->ttl * 60),
            'sub'         => (string) $user->id,
            'tenant_id'   => (string) $user->tenant_id,
            'tenant_code' => $user->tenant?->code,
            'role'        => $user->role,
            'permissions' => $user->permissions ?? [],
            'email'       => $user->email,
            'name'        => $user->name,
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function validateToken(string $token): ?array
    {
        try {
            // Check blacklist
            if ($this->isBlacklisted($token)) {
                return null;
            }

            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            return (array) $decoded;
        } catch (ExpiredException) {
            return null;
        } catch (SignatureInvalidException) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    public function refreshToken(string $token): ?string
    {
        // Allow expired tokens within 1 day refresh window by manually parsing payload
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            // Decode the payload without signature verification to inspect exp
            $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
            $payload = json_decode($payloadJson, true);
            if (!$payload) {
                return null;
            }

            // Reject if expired more than 1 day ago
            $exp = $payload['exp'] ?? 0;
            if ($exp < (time() - 86400)) {
                return null;
            }

            // Now verify signature (ignoring expiry by catching ExpiredException)
            try {
                JWT::decode($token, new Key($this->secret, 'HS256'));
            } catch (ExpiredException) {
                // Expected for expired but refreshable tokens - signature was valid
            }

            $userId = $payload['sub'] ?? null;
            if (!$userId) {
                return null;
            }

            $user = \App\Models\User::with('tenant')->find($userId);
            if (!$user || !$user->is_active) {
                return null;
            }

            $this->blacklistToken($token);
            return $this->generateToken($user);
        } catch (Throwable) {
            return null;
        }
    }

    public function blacklistToken(string $token): void
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            $exp = $decoded->exp ?? (time() + $this->ttl * 60);
            $ttl = max(0, $exp - time());
            Cache::put('jwt_blacklist_' . hash('sha256', $token), true, $ttl);
        } catch (Throwable) {
            // Still blacklist for a safe default period
            Cache::put('jwt_blacklist_' . hash('sha256', $token), true, $this->ttl * 60);
        }
    }

    public function isBlacklisted(string $token): bool
    {
        return (bool) Cache::get('jwt_blacklist_' . hash('sha256', $token));
    }
}
