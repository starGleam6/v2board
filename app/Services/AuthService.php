<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent()
        ]);
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            $userCache = Cache::get("USER_AUTH_CACHE", []);

            if (isset($userCache[$jwt])) {
                $jwtData = $userCache[$jwt];
                if ($jwtData['expires_at'] < now()->timestamp) {
                    unset($userCache[$jwt]);
                    Cache::put("USER_AUTH_CACHE", $userCache, 3600);
                } else {
                    return $jwtData['user'];
                }
            }

            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (!self::checkSession($data['id'], $data['session'])) return false;
    
            $user = User::select(['id', 'email', 'is_admin', 'is_staff'])
                ->find($data['id']);
            if (!$user) return false;

            $userCache[$jwt] = [
                'user' => $user->toArray(),
                'expires_at' => now()->addMinutes(60)->timestamp
            ];

            Cache::put("USER_AUTH_CACHE", $userCache, 3600);
            return $user->toArray();
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        if (!in_array($session, array_keys($sessions))) return false;
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        unset($sessions[$sessionId]);
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function removeAllSession()
    {
        $userCache = Cache::get("USER_AUTH_CACHE", []);

        foreach ($userCache as $jwt => $data) {
            if (isset($data['user']['id']) && $data['user']['id'] == $this->user->id) {
                unset($userCache[$jwt]);
            }
        }
        Cache::put("USER_AUTH_CACHE", $userCache, 3600);

        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        return Cache::forget($cacheKey);
    }
}
