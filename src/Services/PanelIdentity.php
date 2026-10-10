<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Distingue a quien esta USANDO el panel (sesion iniciada o clave de API
 * valida) de un visitante anonimo.
 *
 * El escudo es un middleware global que corre ANTES de iniciar la sesion, asi
 * que no puede preguntarle a Laravel "¿quien es?". Aqui se comprueba a mano,
 * y sin fiarse de nada que el cliente pueda inventar:
 *
 *  - Sesion: la cookie va cifrada con la APP_KEY; solo el panel puede haberla
 *    creado. Se descifra, se saca el id de sesion y se mira en el almacen de
 *    sesiones (Redis/BD/archivos) que ese id existe y contiene un login.
 *    Una cookie inventada o caducada no pasa.
 *  - Clave de API (ptlc_/ptla_): se valida con el propio ApiKey::findToken del
 *    panel (identificador + secreto), no solo el prefijo.
 *
 * Devuelve un identificador estable (hash) para contar PETICIONES POR USUARIO
 * en vez de por IP, o null si es anonimo. Ante cualquier duda o fallo devuelve
 * null: el visitante se trata como anonimo (limite normal), nunca al reves.
 */
class PanelIdentity
{
    /** Validaciones nuevas (fallo de cache) permitidas por IP y minuto: frena el abuso. */
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
            // Cualquier fallo = anonimo.
        }

        return null;
    }

    /**
     * @param callable(): bool $check
     */
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

        // Sin resultado guardado: validar cuesta una lectura de sesion o una consulta.
        // Se limita por IP para que nadie pueda usar credenciales falsas como palanca.
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

        // Laravel antepone "<hash>|" al valor de la cookie cifrada.
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
