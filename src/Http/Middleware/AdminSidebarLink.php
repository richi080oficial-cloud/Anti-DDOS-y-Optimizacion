<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inyecta la entrada "Anti-DDoS y Optimización" en el menu lateral del admin en tiempo
 * de ejecucion.
 *
 * Es la red de seguridad del parche Blade (tools/patch-panel.php): si el
 * layout del panel esta sustituido por un tema, si una actualizacion del panel
 * lo sobrescribio o si el ancla no se encontro durante la instalacion, el
 * enlace sigue apareciendo porque se anade sobre el HTML ya renderizado.
 *
 * Reglas de seguridad que se aplican antes de tocar nada:
 *   - solo peticiones GET no-AJAX de /admin* hechas por un root_admin,
 *   - solo respuestas 200 de tipo text/html (nunca JSON, descargas ni streams),
 *   - si el menu ya contiene un enlace a admin/extensions/antiddos, no se hace nada
 *     (asi el parche Blade y este middleware nunca se duplican).
 *
 * El HTML insertado usa exclusivamente clases de AdminLTE (header, active,
 * fa fa-shield) y no fija ningun color, por lo que hereda el skin activo del
 * panel: tema claro, oscuro o cualquier tema de terceros.
 */
class AdminSidebarLink
{
    /** Marca en HTML (no Blade) para reconocer nuestra propia insercion. */
    private const MARKER = '<!-- antiddos:sidebar -->';

    /**
     * Cabecera de categoria compartida por todos los plugins de Vexa
     * Studios: si ya esta en el HTML (puesta por este mismo middleware en
     * otro plugin, o por el parche Blade estatico de cualquiera de ellos),
     * no se vuelve a anadir.
     */
    private const CATEGORY_HEADER = '<li class="header">VEXA STUDIO</li>';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->applies($request, $response)) {
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

        // Solo se mira DENTRO del menu: la pagina de la extension contiene
        // formularios que apuntan a admin/extensions/antiddos y no deben contar.
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

    /**
     * Determina si la respuesta es una pagina de administracion en HTML que
     * podemos reescribir sin riesgo.
     */
    protected function applies(Request $request, Response $response): bool
    {
        // Descarta StreamedResponse, BinaryFileResponse y JsonResponse.
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

    /**
     * HTML de la entrada. Sin estilos propios: el tema activo decide colores,
     * tipografia y estado hover/active.
     */
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

    /**
     * Limites del menu lateral: [posicion tras el <ul> de apertura, posicion
     * del </ul> que lo cierra].
     *
     * El cierre se calcula contando aperturas y cierres de <ul>, de modo que
     * los submenus (treeview-menu) no confunden la busqueda. Si el tema
     * renombra la clase del <ul> se usa el primer <ul> dentro de
     * <section class="sidebar">.
     *
     * @return array{0: int, 1: int}|null
     */
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