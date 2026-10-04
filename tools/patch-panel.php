<?php

declare(strict_types=1);

/**
 * Integra Anti-DDoS y Optimización en el menu lateral del admin sin romper nada.
 *
 * Uso:
 *   php patch-panel.php patch    /var/www/pterodactyl
 *   php patch-panel.php unpatch  /var/www/pterodactyl
 *   php patch-panel.php status   /var/www/pterodactyl
 *
 * Solo toca resources/views/layouts/admin.blade.php (entrada en el menu
 * lateral). Todo lo insertado queda entre los marcadores antiddos:begin/end,
 * asi que aplicar el parche dos veces no duplica nada y 'unpatch' lo deja como
 * estaba. Antes de escribir se crea <archivo>.antiddos.bak.
 *
 * Es solo comodidad: el middleware AdminSidebarLink anade el mismo enlace en
 * tiempo de ejecucion si este parche no esta (temas de terceros, updates...).
 *
 * Codigos de salida: 0 correcto, 1 error de uso/escritura, 2 ancla no encontrada.
 */

const MARK_BEGIN = '{{-- antiddos:begin --}}';
const MARK_END = '{{-- antiddos:end --}}';

$mode = (string) ($argv[1] ?? '');
$panel = rtrim((string) ($argv[2] ?? ''), '/');

if (!in_array($mode, ['patch', 'unpatch', 'status'], true) || $panel === '') {
    fwrite(STDERR, "Uso: php patch-panel.php patch|unpatch|status /ruta/al/panel\n");
    exit(1);
}

if (!is_file($panel . '/artisan')) {
    fwrite(STDERR, "No parece un panel de Pterodactyl: $panel\n");
    exit(1);
}

$file = $panel . '/resources/views/layouts/admin.blade.php';
$label = 'menu de administracion';

if (!is_file($file)) {
    fwrite(STDERR, "[$label] No se encontro " . relative($panel, $file) . "\n");
    exit(2);
}

$contents = (string) file_get_contents($file);
$patched = str_contains($contents, MARK_BEGIN);

if ($mode === 'status') {
    printf("%-24s %s (%s)\n", $label, $patched ? 'parcheado' : 'sin parchear', relative($panel, $file));
    exit($patched ? 0 : 1);
}

if ($mode === 'unpatch') {
    if (!$patched) {
        echo "[$label] No habia parche que quitar.\n";
        exit(0);
    }

    $updated = removeBlocks($contents);

    if ($updated === $contents) {
        fwrite(STDERR, "[$label] No se pudo limpiar el bloque; revisa " . relative($panel, $file) . " a mano.\n");
        exit(2);
    }

    writeFile($file, $updated);
    echo "[$label] Parche eliminado.\n";
    exit(0);
}

// mode === patch
if ($patched) {
    echo "[$label] Ya estaba parcheado.\n";
    exit(0);
}

$updated = insertSidebarEntry($contents);

if ($updated === null) {
    fwrite(STDERR, "[$label] No se localizo el punto de insercion en " . relative($panel, $file) . ".\n");
    exit(2);
}

writeFile($file, $updated);
echo "[$label] Parche aplicado.\n";
exit(0);

// ---------------------------------------------------------------------------
// Inserciones
// ---------------------------------------------------------------------------

/**
 * Anade la entrada de Anti-DDoS y Optimización al final de <ul class="sidebar-menu">.
 */
function insertSidebarEntry(string $contents): ?string
{
    $close = findSidebarClose($contents);

    if ($close === null) {
        return null;
    }

    $indent = indentOfLineAt($contents, $close);
    $inner = $indent . '    ';

    // Solo clases de AdminLTE: la entrada hereda el skin activo (claro, oscuro
    // o tema de terceros). La cabecera "VEXA STUDIO" es compartida entre todos
    // los plugins de Vexa Studio: se imprime una sola vez por peticion via un
    // guard en $GLOBALS, sin importar cuantos esten instalados.
    $block = wrap($indent, [
        $inner . '@if (empty($GLOBALS[\'__vexastudios_sidebar_header\']))',
        $inner . '    <li class="header">VEXA STUDIO</li>',
        $inner . '    @php($GLOBALS[\'__vexastudios_sidebar_header\'] = true)',
        $inner . '@endif',
        $inner . '<li class="{{ request()->is(\'admin/extensions/antiddos\', \'admin/extensions/antiddos/*\') ? \'active\' : \'\' }}">',
        $inner . '    <a href="{{ \Illuminate\Support\Facades\Route::has(\'admin.extensions.antiddos.index\') ? route(\'admin.extensions.antiddos.index\') : url(\'/admin/extensions/antiddos\') }}">',
        $inner . '        <i class="fa fa-shield"></i> <span>Anti-DDoS y Optimización</span>',
        $inner . '    </a>',
        $inner . '</li>',
    ]);

    $lineStart = lineStartAt($contents, $close);

    return substr($contents, 0, $lineStart) . $block . substr($contents, $lineStart);
}

/**
 * @param array<int, string> $lines
 */
function wrap(string $indent, array $lines): string
{
    $out = $indent . MARK_BEGIN . "\n";
    foreach ($lines as $line) {
        $out .= $line . "\n";
    }

    return $out . $indent . MARK_END . "\n";
}

function removeBlocks(string $contents): string
{
    $pattern = '/[ \t]*' . preg_quote(MARK_BEGIN, '/') . '.*?' . preg_quote(MARK_END, '/') . '[ \t]*\r?\n?/s';
    $updated = preg_replace($pattern, '', $contents);

    return is_string($updated) ? $updated : $contents;
}

// ---------------------------------------------------------------------------
// Utilidades de texto
// ---------------------------------------------------------------------------

/**
 * Posicion del </ul> que cierra el menu lateral del admin.
 *
 * La apertura se localiza por el atributo class (sidebar-menu) y el cierre
 * contando aperturas/cierres de <ul>, asi tolera submenus (treeview-menu).
 */
function findSidebarClose(string $contents): ?int
{
    $after = null;

    if (preg_match('/<ul\b[^>]*\bsidebar-menu\b[^>]*>/is', $contents, $m, PREG_OFFSET_CAPTURE) === 1) {
        $after = (int) $m[0][1] + strlen((string) $m[0][0]);
    } elseif (preg_match('/<section\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bsidebar\b[^"\']*\1[^>]*>/is', $contents, $s, PREG_OFFSET_CAPTURE) === 1) {
        $sectionEnd = (int) $s[0][1] + strlen((string) $s[0][0]);

        if (preg_match('/<ul\b[^>]*>/is', $contents, $u, PREG_OFFSET_CAPTURE, $sectionEnd) === 1) {
            $after = (int) $u[0][1] + strlen((string) $u[0][0]);
        }
    }

    if ($after === null) {
        return null;
    }

    $found = preg_match_all(
        '/<\/?ul\b[^>]*>/is',
        $contents,
        $tags,
        PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE,
        $after
    );

    if ($found === false || $found === 0) {
        return null;
    }

    $depth = 1;

    foreach ($tags[0] as $tag) {
        if (str_starts_with((string) $tag[0], '</')) {
            $depth--;

            if ($depth === 0) {
                return (int) $tag[1];
            }

            continue;
        }

        $depth++;
    }

    return null;
}

function lineStartAt(string $contents, int $offset): int
{
    $break = strrpos(substr($contents, 0, $offset), "\n");

    return $break === false ? 0 : $break + 1;
}

function indentOfLineAt(string $contents, int $offset): string
{
    $start = lineStartAt($contents, $offset);
    $line = substr($contents, $start, $offset - $start);

    return preg_match('/^[ \t]*/', $line, $m) === 1 ? $m[0] : '';
}

function relative(string $panel, string $file): string
{
    return str_starts_with($file, $panel . '/') ? substr($file, strlen($panel) + 1) : $file;
}

function writeFile(string $file, string $contents): void
{
    $backup = $file . '.antiddos.bak';

    if (!is_file($backup) && !copy($file, $backup)) {
        fwrite(STDERR, "No se pudo crear la copia de seguridad $backup\n");
        exit(1);
    }

    if (file_put_contents($file, $contents) === false) {
        fwrite(STDERR, "No se pudo escribir $file (permisos?)\n");
        exit(1);
    }
}
