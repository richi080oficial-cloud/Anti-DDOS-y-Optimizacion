<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class AdminSidebarLink
{
    private const MARKER = '<!-- antiddos:sidebar -->';

    private const CATEGORY_HEADER = '<li class="header">VEXA STUDIO</li>';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->applies($request, $response) || !\Pterodactyl\Extensions\AntiDDoS\Support\Sidebar::visible()) {
            return $response;
        }

        $html = $response->getContent();

        if (!is_string($html) || $html === '' || str_contains($html, self::MARKER)) {
            return $response;
        }

        $bounds = $this->sidebarBounds($html);

        if ($bounds === null) {
            return $response;
        }

        [$openEnd, $close] = $bounds;

        if (str_contains(substr($html, $openEnd, $close - $openEnd), 'admin/extensions/antiddos')) {
            return $response;
        }

        $insertion = str_contains($html, self::CATEGORY_HEADER) ? '' : self::CATEGORY_HEADER . "\n";
        $insertion .= $this->entry($request);

        $updated = substr($html, 0, $close) . $insertion . substr($html, $close);

        $response->setContent($updated);

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($updated));
        }

        return $response;
    }

    protected function applies(Request $request, Response $response): bool
    {
        if (!$response instanceof HttpResponse) {
            return false;
        }

        if ($request->method() !== 'GET' || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (!str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return false;
        }

        if ($response->headers->has('Content-Disposition')) {
            return false;
        }

        if (!$request->is('admin', 'admin/*')) {
            return false;
        }

        $user = $request->user();

        return $user !== null && (bool) $user->root_admin;
    }

    protected function entry(Request $request): string
    {
        $active = $request->is('admin/extensions/antiddos', 'admin/extensions/antiddos/*') ? ' class="active"' : '';

        $url = Route::has('admin.extensions.antiddos.index')
            ? route('admin.extensions.antiddos.index')
            : url('/admin/extensions/antiddos');

        return "\n" . self::MARKER . "\n"
            . '<li' . $active . '>' . "\n"
            . '    <a href="' . e($url) . '">' . "\n"
            . '        <i class="fa fa-shield"></i> <span>Anti-DDoS y Optimización</span>' . "\n"
            . '    </a>' . "\n"
            . '</li>' . "\n";
    }

    protected function sidebarBounds(string $html): ?array
    {
        $openEnd = null;

        if (preg_match('/<ul\b[^>]*\bsidebar-menu\b[^>]*>/is', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $openEnd = (int) $m[0][1] + strlen((string) $m[0][0]);
        } elseif (preg_match('/<section\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bsidebar\b[^"\']*\1[^>]*>/is', $html, $s, PREG_OFFSET_CAPTURE) === 1) {
            $sectionEnd = (int) $s[0][1] + strlen((string) $s[0][0]);

            if (preg_match('/<ul\b[^>]*>/is', $html, $u, PREG_OFFSET_CAPTURE, $sectionEnd) === 1) {
                $openEnd = (int) $u[0][1] + strlen((string) $u[0][0]);
            }
        }

        if ($openEnd === null) {
            return null;
        }

        $count = preg_match_all(
            '/<\/?ul\b[^>]*>/is',
            $html,
            $tags,
            PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE,
            $openEnd
        );

        if ($count === false || $count < 1) {
            return null;
        }

        $depth = 1;

        foreach ($tags[0] as $tag) {
            $depth += str_starts_with((string) $tag[0], '</') ? -1 : 1;

            if ($depth === 0) {
                return [$openEnd, (int) $tag[1]];
            }
        }

        return null;
    }
}