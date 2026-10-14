<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class PanelIdentity
{
    protected const MAX_LOOKUPS = 60;

    public function __construct(protected RateCounter $counter)
    {
    }

    public function resolve(Request $request, string $ip): ?string
    {
        try {
            $cookie = $request->cookies->get((string) config('session.cookie'));

            if (is_string($cookie) && $cookie !== '') {
                $who = $this->cached('s', $cookie, $ip, fn () => $this->validSession($cookie));

                if ($who !== null) {
                    return $who;
                }
            }

            $token = $request->bearerToken();

            if (is_string($token) && preg_match('/^ptl[ca]_[A-Za-z0-9]{20,64}$/', $token) === 1) {
                return $this->cached('k', $token, $ip, fn () => $this->validApiKey($token));
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    protected function cached(string $kind, string $credential, string $ip, callable $check): ?string
    {
        $id = $kind . ':' . substr(hash('sha256', $credential), 0, 24);
        $key = 'antiddos:who:' . $id;
        $known = Cache::get($key);

        if ($known === 1) {
            return $id;
        }

        if ($known === 0) {
            return null;
        }

        if ($this->counter->hit('who', $ip) > self::MAX_LOOKUPS) {
            return null;
        }

        $valid = $check();
        Cache::put($key, $valid ? 1 : 0, $valid ? 60 : 30);

        return $valid ? $id : null;
    }

    protected function validSession(string $cookie): bool
    {
        try {
            $value = Crypt::decrypt($cookie, false);
        } catch (\Throwable $e) {
            try {
                $value = Crypt::decrypt($cookie, true);
            } catch (\Throwable $e2) {
                return false;
            }
        }

        if (!is_string($value)) {
            return false;
        }

        $sessionId = str_contains($value, '|') ? explode('|', $value, 2)[1] : $value;

        if (preg_match('/^[A-Za-z0-9]{40}$/', $sessionId) !== 1) {
            return false;
        }

        $raw = app('session')->driver()->getHandler()->read($sessionId);

        if (!is_string($raw) || $raw === '') {
            return false;
        }

        if (config('session.encrypt')) {
            $raw = Crypt::decrypt($raw, false);
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $name => $id) {
            if (is_string($name) && str_starts_with($name, 'login_') && !empty($id)) {
                return true;
            }
        }

        return false;
    }

    protected function validApiKey(string $token): bool
    {
        $model = \Pterodactyl\Models\ApiKey::class;

        if (!class_exists($model) || !method_exists($model, 'findToken')) {
            return false;
        }

        return $model::findToken($token) !== null;
    }
}
