<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;
use Pterodactyl\Extensions\AntiDDoS\Services\NetworkBlocks;
use Pterodactyl\Extensions\AntiDDoS\Services\PanelIdentity;
use Pterodactyl\Extensions\AntiDDoS\Services\RateCounter;
use Pterodactyl\Extensions\AntiDDoS\Services\SafeIps;
use Symfony\Component\HttpFoundation\Response;

/**
 * Escudo de capa 7 del panel: limita las peticiones por IP y bloquea a quien
 * se pasa. Se registra como middleware GLOBAL (despues de TrustProxies, para
 * ver la IP real detras de Cloudflare/nginx) y antes de sesion, base de datos
 * y PHP "caro": un bloqueado recibe un 429 casi gratis.
 *
 * Orden de decisiones (de mas barato a mas caro, siempre sin SQL):
 *   1. api/remote/* (Wings) y api/antiddos/* (nodos): pasan siempre.
 *   2. IP interna (privada/loopback) o en la lista blanca: pasa siempre.
 *   3. IP bloqueada: 429. IP de un operador (ASN) o pais bloqueado: 403.
 *   4. Assets estaticos: pasan sin contarse.
 *   5. Usuario con sesion/clave de API validas (PanelIdentity): se cuenta por
 *      usuario con un limite alto; pasarse = 429 breve, sin bloquear la IP.
 *   6. Visitante anonimo: se cuenta por IP; si supera el limite, se bloquea
 *      (con duracion creciente por reincidencia) y se responde 429.
 *
 * Si la IP que ve el panel es siempre la de un proxy interno (TRUSTED_PROXIES
 * sin configurar), todas las peticiones parecen internas y el escudo no hace
 * nada: falla "abierto" a proposito, para no bloquear a todos los usuarios.
 */
class ShieldGuard
{
    public function __construct(
        protected BlockManager $blocks,
        protected RateCounter $counter,
        protected NetworkBlocks $nets,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $deny = $this->inspect($request);
        } catch (\Throwable $e) {
            // Un fallo del escudo (cache caida, BD sin migrar...) nunca debe tumbar el panel.
            return $next($request);
        }

        return $deny ?? $next($request);
    }

    protected function inspect(Request $request): ?Response
    {
        $settings = ShieldSetting::snapshot();

        if (!$settings['enabled']) {
            return null;
        }

        $ip = $request->ip();

        if ($ip === null || $request->is('api/remote/*', 'api/antiddos/*')) {
            return null;
        }

        // Una IP de Cloudflare no es la del visitante (falta TRUSTED_PROXIES): bloquearla
        // cortaria el acceso a TODOS los usuarios que pasan por ese nodo de Cloudflare.
        if (IpMatcher::isInternal($ip) || IpMatcher::isCloudflare($ip) || app(SafeIps::class)->isInfrastructure($ip) || IpMatcher::inList($ip, IpMatcher::parseList((string) $settings['whitelist']))) {
            return null;
        }

        $remaining = $this->blocks->remaining($ip);

        if ($remaining !== null) {
            return $this->deny($request, $remaining);
        }

        // Operador (ASN) o pais bloqueado: 403 directo, sin contar ni tocar la BD.
        if ($this->nets->match($ip) !== null) {
            return $this->deny($request, 0, 403);
        }

        if ($request->is(...(array) config('antiddos.exempt_paths', []))) {
            return null;
        }

        if ($request->isMethod('POST') && $request->is(...(array) config('antiddos.auth_paths', []))) {
            if ($this->counter->hit('auth', $ip) > max(1, (int) $settings['auth_limit'])) {
                return $this->ban($request, $ip, 'Demasiados intentos de inicio de sesión');
            }
        }

        // Quien usa el panel con sesion o clave de API validas (consola, archivos, varias
        // pestañas, una oficina con una sola IP...) cuenta POR USUARIO con un limite
        // alto y no gasta el cupo de la IP. Solo el visitante anonimo paga el limite estricto.
        $who = app(PanelIdentity::class)->resolve($request, $ip);

        if ($who !== null) {
            return $this->userLimit($request, $ip, $who, max(120, (int) $settings['user_limit']));
        }

        if ($this->counter->hit('all', $ip) > max(10, (int) $settings['general_limit'])) {
            return $this->ban($request, $ip, 'Exceso de peticiones por minuto');
        }

        return null;
    }

    /**
     * Limites de un usuario reconocido. Pasarse del suyo solo da un 429 breve (no
     * bloquea la IP: puede haber mas gente detras). Un tope por IP, mucho mas alto,
     * sigue frenando a quien reparte el abuso entre muchas sesiones.
     */
    protected function userLimit(Request $request, string $ip, string $who, int $limit): ?Response
    {
        if ($this->counter->hit('usr', $who) > $limit) {
            return $this->deny($request, 30);
        }

        if ($this->counter->hit('usrip', $ip) > $limit * 5) {
            return $this->ban($request, $ip, 'Exceso de peticiones por minuto (varias sesiones)');
        }

        return null;
    }

    protected function ban(Request $request, string $ip, string $reason): Response
    {
        $block = $this->blocks->blockAuto($ip, $reason);
        $seconds = $block->expires_at ? max(1, $block->expires_at->getTimestamp() - time()) : 0;

        return $this->deny($request, $seconds);
    }

    protected function deny(Request $request, int $retryAfter, int $status = 429): Response
    {
        $headers = ['Cache-Control' => 'no-store'];

        if ($retryAfter > 0) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        [$code, $detail, $text] = $status === 403
            ? ['AccessDeniedHttpException', 'Acceso denegado desde tu red.', 'Acceso denegado desde tu red. / Access denied from your network.']
            : ['TooManyRequestsHttpException', 'Demasiadas peticiones. Tu IP está bloqueada temporalmente.', 'Demasiadas peticiones. Tu IP esta bloqueada temporalmente. / Too many requests.'];

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'errors' => [['code' => $code, 'status' => (string) $status, 'detail' => $detail]],
            ], $status, $headers);
        }

        return new Response($text, $status, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
