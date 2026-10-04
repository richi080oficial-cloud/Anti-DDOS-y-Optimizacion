<?php

declare(strict_types=1);

/**
 * Registra o elimina el ServiceProvider de Anti-DDoS y Optimización en el panel.
 *
 * Uso:
 *   php register-provider.php register   /var/www/pterodactyl
 *   php register-provider.php unregister /var/www/pterodactyl
 *
 * Soporta los dos formatos de Laravel: bootstrap/providers.php (Laravel >= 11)
 * y el array 'providers' de config/app.php (Laravel <= 10, Pterodactyl 1.x).
 * Antes de escribir crea una copia de seguridad *.antiddos.bak.
 */

const PROVIDER_CLASS = 'Pterodactyl\\Extensions\\AntiDDoS\\Providers\\AntiDDoSServiceProvider';

$mode = $argv[1] ?? '';
$panel = rtrim((string) ($argv[2] ?? ''), '/');

if (!in_array($mode, ['register', 'unregister'], true) || $panel === '') {
    fwrite(STDERR, "Uso: php register-provider.php register|unregister /ruta/al/panel\n");
    exit(1);
}

$target = locateTarget($panel);

if ($target === null) {
    fwrite(STDERR, "No se encontro bootstrap/providers.php ni config/app.php en $panel\n");
    exit(1);
}

[$file, $kind] = $target;
$contents = (string) file_get_contents($file);

if ($mode === 'register') {
    if (str_contains($contents, PROVIDER_CLASS)) {
        echo "El provider ya estaba registrado en $file\n";
        exit(0);
    }

    $updated = insertProvider($contents, $kind);

    if ($updated === null) {
        fwrite(STDERR, "No se pudo localizar la lista de providers en $file.\n");
        fwrite(STDERR, 'Anade manualmente esta linea al array de providers: ' . PROVIDER_CLASS . "::class,\n");
        exit(1);
    }

    writeFile($file, $updated);
    echo "Provider registrado en $file\n";
    exit(0);
}

if (!str_contains($contents, PROVIDER_CLASS)) {
    echo "El provider no estaba registrado en $file\n";
    exit(0);
}

$pattern = '/^[ \t]*' . preg_quote(PROVIDER_CLASS, '/') . '::class,[ \t]*\r?\n/m';
$updated = preg_replace($pattern, '', $contents, 1);

if ($updated === null || $updated === $contents) {
    // Fallback: elimina solo la referencia, dejando el fichero valido.
    $updated = str_replace(PROVIDER_CLASS . '::class,', '', $contents);
}

writeFile($file, $updated);
echo "Provider eliminado de $file\n";
exit(0);

/**
 * @return array{0: string, 1: string}|null [ruta, tipo]
 */
function locateTarget(string $panel): ?array
{
    $providers = $panel . '/bootstrap/providers.php';
    if (is_file($providers)) {
        return [$providers, 'plain'];
    }

    $app = $panel . '/config/app.php';
    if (is_file($app)) {
        return [$app, 'config'];
    }

    return null;
}

function insertProvider(string $contents, string $kind): ?string
{
    $close = $kind === 'plain'
        ? findPlainArrayClose($contents)
        : findConfigArrayClose($contents);

    if ($close === null) {
        return null;
    }

    $lineStart = (int) (strrpos(substr($contents, 0, $close), "\n") + 1);
    $indentLine = substr($contents, $lineStart, $close - $lineStart);
    $indent = preg_match('/^[ \t]*/', $indentLine, $m) === 1 ? $m[0] : '    ';
    $line = $indent . '    ' . PROVIDER_CLASS . '::class,' . "\n";

    return substr($contents, 0, $lineStart) . $line . substr($contents, $lineStart);
}

/** Posicion del ] que cierra el array devuelto por bootstrap/providers.php. */
function findPlainArrayClose(string $contents): ?int
{
    $open = strpos($contents, '[');

    return $open === false ? null : matchingBracket($contents, $open);
}

/** Posicion del ] que cierra el array 'providers' de config/app.php. */
function findConfigArrayClose(string $contents): ?int
{
    if (preg_match('/([\'"])providers\1\s*=>\s*\[/', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $open = $m[0][1] + strlen($m[0][0]) - 1;

    return matchingBracket($contents, $open);
}

function matchingBracket(string $s, int $open): ?int
{
    $depth = 0;
    $length = strlen($s);
    $quote = null;

    for ($i = $open; $i < $length; $i++) {
        $char = $s[$i];

        if ($quote !== null) {
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            continue;
        }

        if ($char === '[') {
            $depth++;
            continue;
        }

        if ($char === ']') {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
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
