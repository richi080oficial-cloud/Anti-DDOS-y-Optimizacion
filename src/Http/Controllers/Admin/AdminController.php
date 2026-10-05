<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\HealthCheck;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;

class AdminController extends Controller
{
    public function __construct(
        protected BlockManager $blocks,
    ) {
    }

    /** Vuelve a la pantalla de admin abriendo una pestana concreta (#ancla). */
    protected function tab(string $tab): RedirectResponse
    {
        return redirect(route('admin.extensions.antiddos.index') . '#' . $tab);
    }

    public function index(Request $request): View
    {
        $settings = ShieldSetting::snapshot();
        $health = app(HealthCheck::class)->run($request->ip());

        return view('antiddos::admin.index', [
            'health' => $health,
            'version' => $health['version'],
            'fw' => $this->firewallValues($settings, $health['agents']),
            'settings' => $settings,
            'blocks' => $this->blocks->active(),
            'events' => ShieldEvent::query()->orderByDesc('id')->limit(150)->get(),
            'hasApiKey' => (string) (ShieldSetting::read('api_key_hash') ?? '') !== '',
            'panelUrl' => rtrim((string) config('app.url'), '/'),
            'myIp' => $request->ip(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'general_limit' => 'required|integer|min:30|max:100000',
            'auth_limit' => 'required|integer|min:3|max:1000',
            'ban_minutes' => 'required|integer|min:1|max:10080',
            'ban_max_minutes' => 'required|integer|min:1|max:43200',
            'retention_days' => 'required|integer|min:1|max:365',
            'whitelist' => 'nullable|string|max:5000',
        ]);

        $whitelist = IpMatcher::parseList((string) ($data['whitelist'] ?? ''));

        ShieldSetting::write([
            'enabled' => $request->boolean('enabled'),
            'log_events' => $request->boolean('log_events'),
            'general_limit' => $data['general_limit'],
            'auth_limit' => $data['auth_limit'],
            'ban_minutes' => $data['ban_minutes'],
            'ban_max_minutes' => max((int) $data['ban_minutes'], (int) $data['ban_max_minutes']),
            'retention_days' => $data['retention_days'],
            'whitelist' => implode("\n", $whitelist),
        ]);

        ShieldEvent::record('settings', 'Configuración del escudo actualizada', $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('shield')
            ->with('antiddos:success', 'Configuración guardada.');
    }

    /**
     * Valores del firewall a mostrar: lo guardado en el panel; si aun no se ha
     * configurado nada desde aqui, lo que informa el primer agente conectado.
     *
     * @param array<string, mixed> $settings
     * @param \Illuminate\Support\Collection<int, \Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent> $agents
     * @return array<string, mixed>
     */
    protected function firewallValues(array $settings, $agents): array
    {
        $reported = $agents->first(fn ($a) => $a->isOnline())?->payload ?? [];

        $pick = function (string $setting, string $reportKey, mixed $default) use ($settings, $reported) {
            $stored = (string) ($settings[$setting] ?? '');

            if ($stored !== '') {
                return $stored === '-' ? '' : $stored;
            }

            return $reported[$reportKey] ?? $default;
        };

        return [
            'managed' => (string) ($settings['fw_tcp_ports'] ?? '') !== '',
            'tcp' => $pick('fw_tcp_ports', 'tcp', '25565'),
            'udp' => $pick('fw_udp_ports', 'udp', ''),
            'conn_limit' => $pick('fw_conn_limit', 'conn_limit', 30),
            'syn_rate' => $pick('fw_syn_rate', 'syn_rate', 15),
            'udp_rate' => $pick('fw_udp_rate', 'udp_rate', 400),
            'ban_seconds' => $pick('fw_ban_seconds', 'ban_seconds', 900),
            'attack' => (string) ($settings['fw_attack_mode'] ?? '') === '1',
        ];
    }

    /**
     * Valida una lista de puertos «25565,25570:25600» (el firewall admite 15
     * entradas de multiport; un rango cuenta como 2).
     */
    protected function portList(?string $value, string $label): ?string
    {
        $value = preg_replace('/\s+/', '', (string) $value) ?? '';

        if ($value === '') {
            return null;
        }

        $weight = 0;

        foreach (explode(',', $value) as $part) {
            if (!preg_match('/^(\d{1,5})(?::(\d{1,5}))?$/', $part, $m)) {
                throw ValidationException::withMessages(['fw' => "{$label}: «{$part}» no es un puerto válido. Usa el formato 25565 o 25565:25666, separados por comas."]);
            }

            $from = (int) $m[1];
            $to = isset($m[2]) ? (int) $m[2] : $from;

            if ($from < 1 || $to > 65535 || $to < $from) {
                throw ValidationException::withMessages(['fw' => "{$label}: «{$part}» está fuera de rango (1-65535, y el primer puerto debe ser menor que el segundo)."]);
            }

            $weight += isset($m[2]) ? 2 : 1;
        }

        if ($weight > 15) {
            throw ValidationException::withMessages(['fw' => "{$label}: demasiadas entradas (el límite del firewall son 15; un rango cuenta como 2). Agrupa los puertos en rangos."]);
        }

        return $value;
    }

    public function updateFirewall(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tcp' => 'required|string|max:200',
            'udp' => 'nullable|string|max:200',
            'conn_limit' => 'required|integer|min:3|max:1000',
            'syn_rate' => 'required|integer|min:2|max:1000',
            'udp_rate' => 'required|integer|min:20|max:100000',
            'ban_seconds' => 'required|integer|min:30|max:604800',
        ]);

        $tcp = $this->portList($data['tcp'], 'Puertos TCP');
        $udp = $this->portList($data['udp'] ?? '', 'Puertos UDP');

        if ($tcp === null) {
            return $this->tab('shield')->with('antiddos:error', 'Indica al menos un puerto TCP de Minecraft.');
        }

        ShieldSetting::write([
            'fw_tcp_ports' => $tcp,
            'fw_udp_ports' => $udp ?? '-',
            'fw_conn_limit' => $data['conn_limit'],
            'fw_syn_rate' => $data['syn_rate'],
            'fw_udp_rate' => $data['udp_rate'],
            'fw_ban_seconds' => $data['ban_seconds'],
        ]);

        ShieldEvent::record('settings', "Firewall de los nodos actualizado (TCP {$tcp}" . ($udp ? ", UDP {$udp}" : '') . ')', $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('shield')->with('antiddos:success', 'Firewall guardado. Los nodos lo aplicarán en menos de un minuto.');
    }

    public function attackMode(Request $request): RedirectResponse
    {
        $on = $request->input('mode') === 'on';

        ShieldSetting::write(['fw_attack_mode' => $on ? '1' : '0']);
        ShieldEvent::record('attack', $on ? 'Modo «bajo ataque» ACTIVADO' : 'Modo «bajo ataque» desactivado', $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('shield')->with('antiddos:success', $on
            ? 'Modo «bajo ataque» activado: los nodos endurecen los límites en menos de un minuto.'
            : 'Modo «bajo ataque» desactivado.');
    }

    public function ban(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => 'required|ip',
            'minutes' => 'nullable|integer|min:1|max:525600',
            'reason' => 'nullable|string|max:200',
        ]);

        if ($data['ip'] === $request->ip()) {
            return $this->tab('blocks')
                ->with('antiddos:error', 'No puedes bloquear tu propia IP.');
        }

        $this->blocks->block(
            $data['ip'],
            isset($data['minutes']) ? (int) $data['minutes'] : null,
            $data['reason'] ?? 'Bloqueo manual',
            'manual',
            $request->user()?->id
        );

        return $this->tab('blocks')
            ->with('antiddos:success', "IP {$data['ip']} bloqueada. Los nodos la recibirán en menos de un minuto.");
    }

    public function unban(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => 'required|ip']);

        $this->blocks->unblock($data['ip']);

        return $this->tab('blocks')
            ->with('antiddos:success', "IP {$data['ip']} desbloqueada.");
    }

    public function rotateKey(Request $request): RedirectResponse
    {
        $key = Str::random(48);

        ShieldSetting::write(['api_key_hash' => password_hash($key, PASSWORD_DEFAULT)]);
        ShieldEvent::record('apikey', 'Clave de la API regenerada', $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('nodes')
            ->with('antiddos:success', 'Clave nueva generada. Cópiala ahora: no se vuelve a mostrar. Los nodos con la clave anterior dejarán de sincronizar hasta que la actualices.')
            ->with('antiddos:newkey', $key);
    }

    public function clearEvents(): RedirectResponse
    {
        ShieldEvent::query()->delete();

        return $this->tab('events')
            ->with('antiddos:success', 'Registro de eventos vaciado.');
    }
}
