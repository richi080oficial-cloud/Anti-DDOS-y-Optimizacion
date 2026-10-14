<?php

namespace Pterodactyl\Extensions\AntiDDoS\Support;

final class BlockPage
{
    public const KINDS = ['rate', 'login', 'slow', 'manual', 'intel', 'network', 'generic'];

    public static function kindFor(?string $source, ?string $reason): string
    {
        $source = (string) $source;
        $reason = mb_strtolower((string) $reason);

        if ($source === 'manual') {
            return 'manual';
        }

        if ($source === 'intel') {
            return 'intel';
        }

        if (str_contains($reason, 'inicio de sesi')) {
            return 'login';
        }

        if (str_contains($reason, 'peticiones')) {
            return 'rate';
        }

        return 'generic';
    }

    public static function text(string $kind): array
    {
        $texts = [
            'rate' => [
                'Hemos pausado tu', 'acceso',
                'Detectamos demasiadas peticiones seguidas desde tu conexión y, para proteger el panel, la hemos pausado unos minutos. Tus servidores siguen encendidos: solo la web del panel está en pausa.',
                ['Detectamos demasiadas peticiones desde tu IP', 'Tu acceso está en pausa', 'Se reactivará solo al terminar la cuenta atrás'],
                'Peticiones en exceso',
            ],
            'login' => [
                'Demasiados', 'intentos',
                'Se han hecho muchos intentos de inicio de sesión desde tu conexión en muy poco tiempo. Por seguridad, el acceso se ha pausado unos minutos.',
                ['Detectamos muchos intentos de inicio de sesión', 'El acceso está en pausa', 'Podrás volver a intentarlo al terminar la cuenta atrás'],
                'Intentos de inicio de sesión',
            ],
            'slow' => [
                'Vas', 'demasiado rápido',
                'Has hecho muchas acciones en muy poco tiempo. Respira un momento: el panel se reanuda solo en unos segundos y no se pierde nada.',
                ['Has hecho muchas acciones seguidas', 'Pausa breve de seguridad', 'Vuelves al panel automáticamente'],
                'Ritmo de peticiones',
            ],
            'manual' => [
                'Acceso', 'bloqueado',
                'Un administrador ha bloqueado esta conexión. Si crees que es un error, contacta con el equipo del panel y dile tu IP.',
                ['Un administrador ha bloqueado tu IP', 'El acceso al panel está restringido', 'Contacta con el administrador si es un error'],
                'Bloqueo del administrador',
            ],
            'intel' => [
                'Conexión', 'bloqueada',
                'Tu IP aparece en listas públicas de actividad maliciosa, así que se ha bloqueado por seguridad. Si usas una VPN o una red compartida, prueba con otra conexión.',
                ['Tu IP figura en listas públicas de abuso', 'El acceso está bloqueado por seguridad', 'Prueba con otra conexión o contacta con el administrador'],
                'Reputación de la IP',
            ],
            'network' => [
                'Acceso', 'no disponible',
                'El acceso a este panel no está disponible desde tu red o tu ubicación. Si crees que es un error, contacta con el administrador.',
                ['Tu red o ubicación no tiene acceso', 'El panel no responde a esta conexión', 'Contacta con el administrador si es un error'],
                'Restricción de red',
            ],
            'generic' => [
                'Acceso', 'en pausa',
                'Hemos detectado actividad inusual desde tu conexión y la hemos pausado temporalmente para proteger el panel. Tus servidores siguen encendidos.',
                ['Detectamos actividad inusual desde tu IP', 'Tu acceso está en pausa', 'Se reactivará solo cuando termine el bloqueo'],
                'Actividad inusual',
            ],
        ];

        return $texts[$kind] ?? $texts['generic'];
    }

    public static function detail(string $kind): string
    {
        return match ($kind) {
            'network' => 'Acceso denegado desde tu red.',
            'slow' => 'Demasiadas peticiones seguidas. Espera unos segundos.',
            'manual' => 'Tu IP ha sido bloqueada por un administrador.',
            'login' => 'Demasiados intentos de inicio de sesión. Tu IP está bloqueada temporalmente.',
            default => 'Demasiadas peticiones. Tu IP está bloqueada temporalmente.',
        };
    }

    public static function render(string $kind, int $retryAfter, string $ip): string
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'generic';
        [$lead, $accent, $body, $steps, $label] = self::text($kind);

        $timed = $retryAfter > 0 && $kind !== 'network';
        $permanent = $retryAfter === 0 && !in_array($kind, ['network', 'slow'], true);

        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $tone = match ($kind) {
            'slow' => ['#f59e0b', '#fbbf24'],
            'network', 'manual', 'intel' => ['#ef4444', '#f97316'],
            default => ['#8b5cf6', '#22d3ee'],
        };

        $bar = $timed
            ? '<div class="bar"><i id="fill"></i></div><div class="meta"><b id="clock">--:--</b><span>Se desbloquea solo</span></div>'
            : ($permanent
                ? '<div class="bar full"><i></i></div><div class="meta"><b>Bloqueo permanente</b><span>Contacta con el administrador</span></div>'
                : '<div class="meta only"><span>Contacta con el administrador si crees que es un error</span></div>');

        $icons = [
            '<span class="ic ok"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></svg></span>',
            $timed ? '<span class="ic spin"></span>' : '<span class="ic ok"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></svg></span>',
            '<span class="ic idle"></span>',
        ];

        $list = '';
        foreach ($steps as $i => $step) {
            $class = $i === 1 && $timed ? ' class="now"' : ($i === 2 ? ' class="next"' : '');
            $list .= '<li' . $class . '>' . $icons[$i] . '<span>' . $e($step) . '</span></li>';
        }

        $footer = $timed
            ? 'Esta página se actualiza sola y te lleva de vuelta al panel en cuanto termina.'
            : 'Si crees que es un error, contacta con el administrador e indica tu IP.';

        $html = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>%%TITLE%% · Protección</title>
%%REFRESH%%
<style>
*{box-sizing:border-box;margin:0}
:root{--a:%%A%%;--b:%%B%%}
html,body{height:100%}
body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#070a12;color:#e8eaf2;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;overflow:hidden;-webkit-font-smoothing:antialiased}
.glow{position:fixed;border-radius:50%;filter:blur(110px);opacity:.55;pointer-events:none}
.g1{width:520px;height:520px;left:-140px;top:-120px;background:#4c3fc4}
.g2{width:560px;height:560px;right:-200px;bottom:-160px;background:#14899f}
.g3{width:460px;height:300px;left:34%;bottom:-170px;background:#7a2a8c;opacity:.45}
.card{position:relative;width:min(580px,100%);padding:40px 38px 30px;border-radius:28px;background:rgba(22,22,34,.82);border:1px solid rgba(255,255,255,.09);box-shadow:0 30px 80px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.05);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);text-align:center;animation:in .5s ease both}
@keyframes in{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:none}}
.logo{width:78px;height:78px;margin:0 auto 22px;border-radius:22px;background:linear-gradient(135deg,var(--a),var(--b));display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 10px rgba(255,255,255,.03),0 14px 40px color-mix(in srgb,var(--a) 55%,transparent)}
.logo svg{width:38px;height:38px;fill:none;stroke:#fff;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.eyebrow{font-size:11px;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:#9aa0b8}
h1{margin-top:12px;font-size:32px;line-height:1.15;font-weight:800;letter-spacing:-.01em;color:#fff}
h1 em{font-style:normal;background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}
.lead{margin:14px auto 0;max-width:470px;font-size:15px;line-height:1.6;color:#b4b8cc}
.bar{margin-top:28px;height:12px;border-radius:99px;background:rgba(255,255,255,.08);overflow:hidden}
.bar i{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,var(--a),var(--b));transition:width .9s linear}
.bar.full i{width:100%}
.meta{display:flex;justify-content:space-between;align-items:baseline;margin-top:12px;padding:0 2px}
.meta b{font-size:22px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums}
.meta span{font-size:12.5px;color:#9aa0b8}
.meta.only{justify-content:center;margin-top:24px}
ul{list-style:none;margin:24px auto 0;padding:0;display:inline-flex;flex-direction:column;gap:13px;text-align:left}
li{display:flex;align-items:center;gap:12px;font-size:14.5px;color:#d7daea}
li.now{color:#fff;font-weight:700}
li.next{color:#8b90a8}
.ic{flex:none;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center}
.ic.ok{background:#22c55e}
.ic.ok svg{width:14px;height:14px;fill:none;stroke:#fff;stroke-width:3.2;stroke-linecap:round;stroke-linejoin:round}
.ic.idle{border:2px solid rgba(255,255,255,.18)}
.ic.spin{border:2.5px solid color-mix(in srgb,var(--b) 28%,transparent);border-top-color:var(--b);animation:sp .9s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.chips{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:26px;padding-top:20px;border-top:1px solid rgba(255,255,255,.08)}
.chip{font-size:12px;padding:6px 12px;border-radius:99px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);color:#c4c8dc}
.chip b{color:#fff;font-weight:600}
.foot{margin-top:16px;font-size:12px;line-height:1.5;color:#8d92aa}
.en{margin-top:4px;font-size:11px;color:#6d7289}
@media (max-width:520px){.card{padding:32px 22px 24px;border-radius:24px}h1{font-size:26px}}
@media (prefers-reduced-motion:reduce){.card,.ic.spin{animation:none}.bar i{transition:none}}
</style>
</head>
<body data-end="%%END%%" data-total="%%TOTAL%%">
<div class="glow g1"></div><div class="glow g2"></div><div class="glow g3"></div>
<main class="card">
<div class="logo"><svg viewBox="0 0 24 24"><path d="M12 3l7.5 3v5.4c0 4.4-3.1 8.2-7.5 9.6-4.4-1.4-7.5-5.2-7.5-9.6V6L12 3z"/><path d="M9.2 12.2l2 2 3.6-4"/></svg></div>
<div class="eyebrow">Vexa Studio · Protección</div>
<h1>%%LEAD%% <em>%%ACCENT%%</em></h1>
<p class="lead">%%BODY%%</p>
%%BAR%%
<ul>%%LIST%%</ul>
<div class="chips"><span class="chip">Tu IP: <b>%%IP%%</b></span><span class="chip">Motivo: <b>%%LABEL%%</b></span></div>
<p class="foot">%%FOOT%%</p>
<p class="en">Temporarily paused to protect the panel.</p>
</main>
<script>
(function () {
  var b = document.body, end = parseInt(b.getAttribute('data-end'), 10) || 0, total = parseInt(b.getAttribute('data-total'), 10) || 0;
  var clock = document.getElementById('clock'), fill = document.getElementById('fill');
  if (!end || !clock) { return; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    var left = Math.max(0, Math.round((end - Date.now()) / 1000));
    var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
    clock.textContent = (h ? h + ':' + pad(m) : pad(m)) + ':' + pad(s);
    if (fill && total) { fill.style.width = Math.min(100, Math.max(2, (1 - left / total) * 100)) + '%'; }
    if (left <= 0) { setTimeout(function () { location.reload(); }, 800); return; }
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
</body>
</html>
HTML;

        $end = $timed ? (time() + $retryAfter) * 1000 : 0;

        return strtr($html, [
            '%%TITLE%%' => $e($lead . ' ' . $accent),
            '%%REFRESH%%' => $timed ? '<meta http-equiv="refresh" content="' . ($retryAfter + 3) . '">' : '',
            '%%A%%' => $tone[0],
            '%%B%%' => $tone[1],
            '%%END%%' => (string) $end,
            '%%TOTAL%%' => (string) ($timed ? $retryAfter : 0),
            '%%LEAD%%' => $e($lead),
            '%%ACCENT%%' => $e($accent),
            '%%BODY%%' => $e($body),
            '%%BAR%%' => $bar,
            '%%LIST%%' => $list,
            '%%IP%%' => $e($ip),
            '%%LABEL%%' => $e($label),
            '%%FOOT%%' => $e($footer),
        ]);
    }
}
