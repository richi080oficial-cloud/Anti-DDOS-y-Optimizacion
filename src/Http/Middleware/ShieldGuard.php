<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;
use Pterodactyl\Extensions\AntiDDoS\Services\NetworkBlocks;
use Pterodactyl\Extensions\AntiDDoS\Services\PanelIdentity;
use Pterodactyl\Extensions\AntiDDoS\Services\RateCounter;
use Pterodactyl\Extensions\AntiDDoS\Services\SafeIps;
use Pterodactyl\Extensions\AntiDDoS\Support\BlockPage;
use Symfony\Component\HttpFoundation\Response;

class ShieldGuard
{
    protected const TRUSTED_KEY = 'antiddos:okip:';

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

        if (IpMatcher::isInternal($ip) || IpMatcher::isCloudflare($ip) || app(SafeIps::class)->isInfrastructure($ip) || IpMatcher::inList($ip, IpMatcher::parseList((string) $settings['whitelist']))) {
            return null;
        }

        $remaining = $this->blocks->remaining($ip);

        if ($remaining !== null) {
            [$source, $why] = $this->blocks->why($ip) ?? ['auto', ''];

            return $this->deny($request, $remaining, 429, BlockPage::kindFor($source, $why), $ip);
        }

        if ($this->nets->match($ip) !== null) {
            return $this->deny($request, 0, 403, 'network', $ip);
        }

        if ($request->is(...(array) config('antiddos.exempt_paths', []))) {
            return null;
        }

        if ($request->isMethod('POST') && $request->is(...(array) config('antiddos.auth_paths', []))) {
            if ($this->counter->hit('auth', $ip) > max(1, (int) $settings['auth_limit'])) {
                return $this->ban($request, $ip, 'Demasiados intentos de inicio de sesión');
            }
        }

        $who = app(PanelIdentity::class)->resolve($request, $ip);

        if ($who !== null) {
            Cache::add(self::TRUSTED_KEY . $ip, 1, 1800);

            return $this->userLimit($request, $ip, $who, max(120, (int) $settings['user_limit']));
        }

        $base = max(10, (int) $settings['general_limit']);
        $trusted = Cache::has(self::TRUSTED_KEY . $ip);
        $limit = $trusted ? $base * 3 : $base;
        $count = $this->counter->hit('all', $ip);

        if ($count <= $limit) {
            return null;
        }

        if ($count > $limit * 2) {
            return $this->ban($request, $ip, 'Exceso de peticiones por minuto');
        }

        return $this->soften($request, $ip, $trusted ? 6 : 3);
    }

    protected function soften(Request $request, string $ip, int $strikes): ?Response
    {
        $window = intdiv(time(), 60);

        if (Cache::add('antiddos:soft:' . $ip . ':' . $window, 1, 90) && $this->counter->hit('warn', $ip, 600) >= $strikes) {
            return $this->ban($request, $ip, 'Exceso de peticiones por minuto (reincidente)');
        }

        return $this->deny($request, 60 - (time() % 60), 429, 'slow', $ip);
    }

    protected function userLimit(Request $request, string $ip, string $who, int $limit): ?Response
    {
        if ($this->counter->hit('usr', $who) > $limit) {
            return $this->deny($request, 60 - (time() % 60), 429, 'slow', $ip);
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

        return $this->deny($request, $seconds, 429, BlockPage::kindFor('auto', $reason), $ip);
    }

    protected function deny(Request $request, int $retryAfter, int $status = 429, string $kind = 'generic', string $ip = ''): Response
    {
        $headers = ['Cache-Control' => 'no-store'];

        if ($retryAfter > 0) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        $code = $status === 403 ? 'AccessDeniedHttpException' : 'TooManyRequestsHttpException';
        $detail = BlockPage::detail($kind);

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'errors' => [['code' => $code, 'status' => (string) $status, 'detail' => $detail]],
            ], $status, $headers);
        }

        return new Response(BlockPage::render($kind, $retryAfter, $ip), $status, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
