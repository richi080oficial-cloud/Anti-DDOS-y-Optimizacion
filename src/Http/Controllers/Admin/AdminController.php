<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\AutoBlocker;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\HealthCheck;
use Pterodactyl\Extensions\AntiDDoS\Services\IpIntel;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;
use Pterodactyl\Extensions\AntiDDoS\Services\LiveTraffic;
use Pterodactyl\Extensions\AntiDDoS\Services\MetricsService;
use Pterodactyl\Extensions\AntiDDoS\Services\MinecraftPorts;
use Pterodactyl\Extensions\AntiDDoS\Services\NetworkBlocks;
use Pterodactyl\Extensions\AntiDDoS\Services\PortAdvisor;
use Pterodactyl\Extensions\AntiDDoS\Services\PortChanger;
use Pterodactyl\Extensions\AntiDDoS\Services\ReputationLists;
use Pterodactyl\Extensions\AntiDDoS\Services\SafeIps;
use Pterodactyl\Extensions\AntiDDoS\Services\TrafficEngine;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;

class AdminController extends Controller
{
    public function __construct(
        protected BlockManager $blocks,
        protected MinecraftPorts $minecraft,
        protected PortAdvisor $advisor,
        protected PortChanger $changer,
        protected MetricsService $metrics,
        protected LiveTraffic $live,
        protected IpIntel $intel,
        protected SafeIps $safe,
        protected ReputationLists $reputation,
        protected NetworkBlocks $nets,
    ) {
    }

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
            'mc' => ['plan' => $plan = $this->minecraft->plan(), 'eggs' => $this->minecraft->eggs()],
            'ports' => $this->portRows($fw = $this->firewallValues($settings, $health['agents']), $plan),
            'metricsHosts' => $health['agents']->pluck('host')->all(),
            'intelStatus' => $this->reputation->status(),
            'intelRefreshedAt' => Cache::get('antiddos:rep:refreshed'),
            'abuseKeySet' => $this->intel->abuseKey() !== null,
            'banMode' => (string) (($settings['fw_ban_mode'] ?? '') ?: ($health['agents']->first(fn ($a) => $a->isOnline())?->payload['ban_mode'] ?? 'balanced')),
            'banModeManaged' => (string) ($settings['fw_ban_mode'] ?? '') !== '',
            'settings' => $settings,
            'blocks' => $this->blocks->active(),
            'netBlocks' => $this->nets->active(),
            'cfExpression' => $this->nets->cloudflareExpression(),
            'events' => ShieldEvent::query()->orderByDesc('id')->limit(150)->get(),
            'hasApiKey' => (string) (ShieldSetting::read('api_key_hash') ?? '') !== '',
            'apiKey' => ($hc = app(HealthCheck::class))->apiKey(),
            'installCmd' => $hc->bootstrapCommand(true),
            'panelUrl' => rtrim((string) config('app.url'), '/'),
            'myIp' => $request->ip(),
            'safeAuto' => $this->safe->automatic(),
            'safeManual' => $this->protectedRows($settings),
            'serverIps' => array_values(array_filter(
                $this->safe->localAddresses(),
                fn (string $ip) => !IpMatcher::isInternal($ip) && !IpMatcher::inList($ip, IpMatcher::parseList((string) $settings['whitelist']))
            )),
        ]);
    }

    protected function protectedRows(array $settings): array
    {
        $labels = json_decode((string) ($settings['whitelist_labels'] ?? ''), true);
        $labels = is_array($labels) ? $labels : [];
        $rows = [];

        foreach (IpMatcher::parseList((string) ($settings['whitelist'] ?? '')) as $entry) {
            $rows[] = ['entry' => $entry, 'label' => (string) ($labels[$entry] ?? '')];
        }

        return $rows;
    }

    protected function tooWide(string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return false;
        }

        [$net, $bits] = explode('/', $entry, 2);

        return str_contains($net, ':') ? (int) $bits < 32 : (int) $bits < 16;
    }

    public function addProtected(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entries' => 'required|string|max:5000',
            'label' => 'nullable|string|max:60',
        ]);

        $entries = IpMatcher::parseList((string) $data['entries']);

        if ($entries === []) {
            return $this->tab('safe')->with('antiddos:error', 'No he encontrado ninguna IP válida. Escribe una IP o un rango por línea (por ejemplo 203.0.113.7 o 198.51.100.0/24).');
        }

        $wide = array_values(array_filter($entries, fn (string $e) => $this->tooWide($e)));

        if ($wide !== []) {
            return $this->tab('safe')->with('antiddos:error', 'Rango demasiado grande (' . implode(', ', $wide) . '): protegerlo dejaría sin defensa a media internet. Usa rangos de /16 o más pequeños.');
        }

        $list = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));
        $added = [];

        foreach ($entries as $entry) {
            if (!in_array($entry, $list, true)) {
                $list[] = $entry;
                $added[] = $entry;
            }
        }

        $labels = json_decode((string) ShieldSetting::read('whitelist_labels', ''), true);
        $labels = is_array($labels) ? $labels : [];
        $label = trim((string) ($data['label'] ?? ''));

        if ($label !== '') {
            foreach ($entries as $entry) {
                $labels[$entry] = $label;
            }
        }

        ShieldSetting::write([
            'whitelist' => implode("\n", $list),
            'whitelist_labels' => json_encode($labels, JSON_UNESCAPED_UNICODE),
        ]);

        $freed = 0;

        foreach ($this->blocks->active() as $block) {
            if (IpMatcher::inList($block->ip, $entries) && $this->blocks->unblock($block->ip)) {
                $freed++;
            }
        }

        $this->safe->forget();
        ShieldEvent::record('whitelist', 'IPs protegidas: ' . implode(', ', $entries), null, ['user_id' => $request->user()?->id]);

        $text = $added === []
            ? 'Esas IPs ya estaban protegidas.'
            : count($added) . ' ' . (count($added) === 1 ? 'IP protegida' : 'IPs protegidas') . ': nunca se bloquearán (ni en el panel ni en el firewall de los nodos, en menos de un minuto).';

        if ($freed > 0) {
            $text .= " Se han desbloqueado {$freed} bloqueo(s) que tenían.";
        }

        return $this->tab('safe')->with('antiddos:success', $text);
    }

    public function removeProtected(Request $request): RedirectResponse
    {
        $data = $request->validate(['entry' => 'required|string|max:64']);

        $list = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));
        $list = array_values(array_filter($list, fn (string $e) => $e !== $data['entry']));

        $labels = json_decode((string) ShieldSetting::read('whitelist_labels', ''), true);
        $labels = is_array($labels) ? $labels : [];
        unset($labels[$data['entry']]);

        ShieldSetting::write([
            'whitelist' => implode("\n", $list),
            'whitelist_labels' => json_encode($labels, JSON_UNESCAPED_UNICODE),
        ]);

        $this->safe->forget();
        ShieldEvent::record('whitelist', "IP {$data['entry']} quitada de las protegidas", null, ['user_id' => $request->user()?->id]);

        return $this->tab('safe')->with('antiddos:success', "{$data['entry']} ya no está en la lista de protegidas.");
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'general_limit' => 'required|integer|min:30|max:100000',
            'user_limit' => 'required|integer|min:120|max:200000',
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
            'user_limit' => $data['user_limit'],
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
            'auto' => (bool) ($settings['fw_auto'] ?? true),
            'extra' => (bool) ($settings['fw_extra_ports'] ?? true),
        ];
    }

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

    protected function portRows(array $fw, array $plan): array
    {
        $counts = ['error' => 0, 'warn' => 0, 'ok' => 0];
        $eggs = $this->minecraft->eggs()->where('selected', true)->keyBy('id');

        if ($eggs->isEmpty()) {
            return ['rows' => [], 'counts' => $counts];
        }

        $servers = Server::query()
            ->with('node:id,name,daemonListen,daemonSFTP')
            ->whereIn('egg_id', $eggs->keys()->all())
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'uuidShort', 'egg_id', 'node_id', 'allocation_id']);

        $allocations = Allocation::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->get(['id', 'server_id', 'ip', 'port'])
            ->groupBy('server_id');

        $protected = $fw['managed'] ? ['tcp' => $plan['tcp'], 'udp' => $plan['udp']] : null;
        $rows = [];

        foreach ($servers as $server) {
            $mine = $allocations->get($server->id, collect());
            $primary = $mine->firstWhere('id', $server->allocation_id);

            if ($primary === null) {
                continue;
            }

            $bedrock = (bool) ($eggs[$server->egg_id]['bedrock'] ?? false);
            $reserved = $server->node ? [(int) $server->node->daemonListen, (int) $server->node->daemonSFTP] : [];
            $issues = $this->advisor->assess((int) $primary->port, $bedrock, $protected, $reserved);
            $level = $this->advisor->worst($issues);
            $counts[$level]++;

            $rows[] = [
                'id' => $server->id,
                'name' => $server->name,
                'node' => $server->node->name ?? '—',
                'ip' => $primary->ip,
                'port' => (int) $primary->port,
                'bedrock' => $bedrock,
                'extras' => $mine->where('id', '!=', $server->allocation_id)->pluck('port')->sort()->values()->all(),
                'issues' => $issues,
                'level' => $level,
            ];
        }

        $order = ['error' => 0, 'warn' => 1, 'ok' => 2];
        usort($rows, fn (array $a, array $b) => [$order[$a['level']], $a['name']] <=> [$order[$b['level']], $b['name']]);

        return ['rows' => $rows, 'counts' => $counts];
    }

    public function trafficData(Request $request): JsonResponse
    {
        $data = $request->validate([
            'range' => 'nullable|in:live,' . implode(',', array_keys(MetricsService::RANGES)),
            'view' => 'nullable|in:' . implode(',', TrafficEngine::VIEWS),
            'host' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $range = $data['range'] ?? 'live';
        $view = $data['view'] ?? 'network';
        $host = $data['host'] ?? null;

        $response = $range === 'live'
            ? $this->live->series($host, $view)
            : $this->metrics->series($range, $host, $view);

        $tables = Cache::remember('antiddos:tables:' . md5($host . '|' . $request->ip()), 3, fn () => [
            'top' => $this->topIps($host, $request->ip()),
            'fw_bans' => $this->firewallBans($host, $request->ip()),
        ]);
        $response['top'] = $tables['top'];
        $response['fw_bans'] = $tables['fw_bans'];
        $response['ban_mode'] = (string) (ShieldSetting::read('fw_ban_mode', '') ?: 'balanced');

        return (new JsonResponse($response))->header('Cache-Control', 'no-store');
    }

    protected function topIps(?string $host, ?string $requestIp = null): array
    {
        $totals = [];

        foreach (ShieldAgent::query()->when($host, fn ($q) => $q->where('host', $host))->get() as $agent) {
            if (!$agent->isOnline()) {
                continue;
            }

            foreach ((array) ($agent->payload['top_ips'] ?? []) as $row) {
                $totals[$row['ip']] = ($totals[$row['ip']] ?? 0) + (int) $row['conns'];
            }
        }

        foreach (array_keys($totals) as $ip) {
            if ($this->safe->isInfrastructure((string) $ip)) {
                unset($totals[$ip]);
            }
        }

        arsort($totals);
        $blocked = $this->blocks->map();
        $out = [];

        foreach (array_slice($totals, 0, 10, true) as $ip => $conns) {
            $ip = (string) $ip;
            $out[] = [
                'ip' => $ip,
                'conns' => $conns,
                'blocked' => array_key_exists($ip, $blocked),
                'net_blocked' => $this->nets->match($ip),
                'safe_reason' => $this->safe->protectedReason($ip, $requestIp),
                'intel' => $this->intel->peek($ip),
            ];
        }

        return $out;
    }

    protected function firewallBans(?string $host, ?string $requestIp = null): array
    {
        $rows = [];

        foreach (ShieldAgent::query()->when($host, fn ($q) => $q->where('host', $host))->get() as $agent) {
            foreach ((array) ($agent->payload['fw_bans'] ?? []) as $b) {
                $key = $b['ip'];

                if (!isset($rows[$key]) || $b['left'] > $rows[$key]['left']) {
                    $rows[$key] = $b + ['host' => $agent->host];
                }
            }
        }

        uasort($rows, fn (array $a, array $b) => $b['left'] <=> $a['left']);
        $out = [];

        foreach (array_slice($rows, 0, 30) as $b) {
            $out[] = $b + [
                'net_blocked' => $this->nets->match($b['ip']),
                'safe_reason' => $this->safe->protectedReason($b['ip'], $requestIp),
                'intel' => $this->intel->peek($b['ip']),
            ];
        }

        return $out;
    }

    public function changePort(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'port' => 'nullable|integer|min:1024|max:65535',
            'min' => 'nullable|integer|min:1024|max:65535',
            'max' => 'nullable|integer|min:1024|max:65535',
        ]);

        $server = Server::query()->findOrFail($data['server_id']);
        $port = isset($data['port'])
            ? (int) $data['port']
            : $this->changer->suggest($server, [], (int) ($data['min'] ?? PortAdvisor::SAFE_MIN), (int) ($data['max'] ?? PortAdvisor::SAFE_MAX));

        if (!$port) {
            return $this->tab('ports')->with('antiddos:error', 'No se encontró ningún puerto libre recomendado en ese nodo. Indica uno a mano.');
        }

        $report = $this->changer->change($server, $port, $request->boolean('restart'), $request->user()?->id);

        return $this->portReport([$server->name => $report], 1);
    }

    public function autoPorts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'servers' => 'required|array|min:1',
            'servers.*' => 'integer|min:1',
            'min' => 'nullable|integer|min:1024|max:65535',
            'max' => 'nullable|integer|min:1024|max:65535',
        ]);

        @set_time_limit(300);

        $min = (int) ($data['min'] ?? PortAdvisor::SAFE_MIN);
        $max = (int) ($data['max'] ?? PortAdvisor::SAFE_MAX);

        if ($max - $min < 50) {
            return $this->tab('ports')->with('antiddos:error', 'El rango de puertos debe tener al menos 50 puertos de margen.');
        }

        $ids = array_slice(array_values(array_unique(array_map('intval', $data['servers']))), 0, 40);
        $reports = [];
        $used = [];

        foreach (Server::query()->whereIn('id', $ids)->get() as $server) {
            $port = $this->changer->suggest($server, $used, $min, $max);

            if (!$port) {
                $reports[$server->name] = ['ok' => [], 'warn' => ['No se encontró un puerto libre recomendado.']];

                continue;
            }

            $used[] = $port;
            $reports[$server->name] = $this->changer->change($server, $port, $request->boolean('restart'), $request->user()?->id);
        }

        return $this->portReport($reports, count($ids));
    }

    protected function portReport(array $reports, int $total): RedirectResponse
    {
        $lines = [];
        $failed = 0;

        foreach ($reports as $name => $report) {
            foreach ($report['ok'] as $msg) {
                $lines[] = [$name, 'ok', $msg];
            }

            foreach ($report['warn'] as $msg) {
                $lines[] = [$name, 'warn', $msg];
            }

            if ($report['ok'] === [] || $report['warn'] !== []) {
                $failed++;
            }
        }

        return $this->tab('ports')
            ->with($failed === $total && $total > 0 ? 'antiddos:error' : 'antiddos:success', sprintf('%d servidor(es) procesado(s)%s.', $total, $failed > 0 ? ", {$failed} con avisos" : ''))
            ->with('antiddos:report', array_slice($lines, 0, 120));
    }

    public function updateFirewall(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tcp' => 'nullable|string|max:200',
            'udp' => 'nullable|string|max:200',
            'conn_limit' => 'required|integer|min:3|max:1000',
            'syn_rate' => 'required|integer|min:2|max:1000',
            'udp_rate' => 'required|integer|min:20|max:100000',
            'ban_seconds' => 'required|integer|min:30|max:604800',
            'eggs' => 'nullable|array',
            'eggs.*' => 'integer|min:1',
        ]);

        $tcp = $this->portList($data['tcp'] ?? '', 'Puertos TCP');
        $udp = $this->portList($data['udp'] ?? '', 'Puertos UDP');
        $auto = $request->boolean('auto');

        if ($tcp === null && !$auto) {
            return $this->tab('shield')->with('antiddos:error', 'Indica al menos un puerto TCP, o activa la detección automática de servidores de Minecraft.');
        }

        $eggsValue = '';

        if ($request->has('eggs_present')) {
            $selected = collect($data['eggs'] ?? [])->map(fn ($v) => (int) $v)->unique()->sort()->values();
            $detected = $this->minecraft->eggs()->where('detected', true)->pluck('id')->map(fn ($v) => (int) $v)->sort()->values();
            $eggsValue = $selected->all() === $detected->all() ? '' : ($selected->isEmpty() ? '0' : $selected->implode(','));
        }

        ShieldSetting::write([
            'fw_tcp_ports' => $tcp ?? '-',
            'fw_udp_ports' => $udp ?? '-',
            'fw_conn_limit' => $data['conn_limit'],
            'fw_syn_rate' => $data['syn_rate'],
            'fw_udp_rate' => $data['udp_rate'],
            'fw_ban_seconds' => $data['ban_seconds'],
            'fw_auto' => $auto,
            'fw_extra_ports' => $request->boolean('extra'),
            'fw_egg_ids' => $eggsValue,
        ]);

        $this->minecraft->flush();
        $plan = $this->minecraft->plan();

        ShieldEvent::record(
            'settings',
            'Firewall de los nodos actualizado (TCP ' . ($plan['tcp'] ?: '—') . ', UDP ' . ($plan['udp'] ?: '—') . ($auto ? ', detección automática' : '') . ')',
            $request->ip(),
            ['user_id' => $request->user()?->id]
        );

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

        try {
            $this->blocks->block(
                $data['ip'],
                isset($data['minutes']) ? (int) $data['minutes'] : null,
                $data['reason'] ?? 'Bloqueo manual',
                'manual',
                $request->user()?->id,
                $request->ip()
            );
        } catch (\DomainException $e) {
            return $this->tab('blocks')->with('antiddos:error', "No se bloquea {$data['ip']}: " . $e->getMessage());
        }

        return $this->tab('blocks')
            ->with('antiddos:success', "IP {$data['ip']} bloqueada. Los nodos la recibirán en menos de un minuto.");
    }

    public function blockNetwork(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:asn,country',
            'value' => 'required|string|max:120',
            'hours' => 'nullable|integer|min:1|max:8760',
            'reason' => 'nullable|string|max:190',
        ]);

        @set_time_limit(120);

        try {
            $block = $this->nets->block(
                $data['kind'],
                $data['value'],
                isset($data['hours']) ? (int) $data['hours'] : null,
                $data['reason'] ?? null,
                $request->user()?->id,
                $request->ip(),
                $request->boolean('force')
            );
        } catch (\DomainException|\RuntimeException $e) {
            return $this->tab('asn')->with('antiddos:error', 'No se bloquea: ' . $e->getMessage());
        }

        return $this->tab('asn')->with('antiddos:success', sprintf(
            '%s bloqueado: %d rango(s), %s IPs. El panel ya le responde 403 y los nodos lo aplican en menos de un minuto.',
            $block->label(), $block->prefix_count, number_format($block->ip_count, 0, ',', '.')
        ));
    }

    public function unblockNetwork(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|in:asn,country', 'value' => 'required|string|max:120']);

        try {
            $done = $this->nets->unblock($data['kind'], $data['value']);
        } catch (\DomainException $e) {
            return $this->tab('asn')->with('antiddos:error', $e->getMessage());
        }

        return $this->tab('asn')->with($done ? 'antiddos:success' : 'antiddos:error', $done
            ? 'Desbloqueado. Los nodos lo aplican en menos de un minuto.'
            : 'No estaba bloqueado.');
    }

    public function refreshNetworks(): RedirectResponse
    {
        @set_time_limit(180);

        $results = $this->nets->refresh();
        $lines = [];

        foreach ($results as $label => $result) {
            $lines[] = "{$label}: {$result}";
        }

        return $this->tab('asn')->with('antiddos:success', $lines === [] ? 'No hay redes bloqueadas.' : 'Rangos actualizados — ' . implode(' · ', $lines));
    }

    public function whitelistIp(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => 'required|ip']);

        $list = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));

        if (!in_array($data['ip'], $list, true)) {
            $list[] = $data['ip'];
            ShieldSetting::write(['whitelist' => implode("\n", $list)]);
        }

        $this->blocks->unblock($data['ip']);
        $this->safe->forget();
        ShieldEvent::record('whitelist', "IP {$data['ip']} añadida a la lista blanca (falso positivo corregido)", $data['ip'], ['user_id' => $request->user()?->id]);

        return $this->tab('status')->with('antiddos:success', "{$data['ip']} añadida a la lista blanca: ya no se bloqueará (se aplica a los nodos en menos de un minuto).");
    }

    public function updatePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate(['ban_mode' => 'required|in:monitor,balanced,strict']);

        ShieldSetting::write(['fw_ban_mode' => $data['ban_mode']]);
        ShieldEvent::record('settings', 'Política de bloqueo de los nodos: ' . $data['ban_mode'], $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('shield')->with('antiddos:success', 'Política de bloqueo guardada. Los nodos la aplican en menos de un minuto.');
    }

    public function updateIntel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'abuseipdb_key' => 'nullable|string|max:200',
            'auto_block_duration' => 'nullable|in:24h,7d,forever',
        ]);

        ShieldSetting::write([
            'intel_enabled' => $request->boolean('intel_enabled'),
            'auto_block_malicious' => $request->boolean('auto_block_malicious'),
            'auto_block_duration' => $data['auto_block_duration'] ?? (string) ShieldSetting::read('auto_block_duration', '24h'),
        ]);

        if ($request->boolean('abuseipdb_clear')) {
            $this->intel->setAbuseKey(null);
        } elseif (trim((string) $request->input('abuseipdb_key')) !== '') {
            $this->intel->setAbuseKey(trim((string) $request->input('abuseipdb_key')));
        }

        return $this->tab('shield')->with('antiddos:success', 'Configuración de origen y reputación guardada.' . ($request->boolean('auto_block_malicious') && $request->boolean('intel_enabled') ? ' Bloqueo automático de IPs maliciosas ACTIVADO (' . app(AutoBlocker::class)->durationLabel() . ').' : ''));
    }

    public function recheckIp(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => 'required|ip']);

        @set_time_limit(60);
        $intel = $this->intel->recheck($data['ip']);

        return $this->tab('traffic')->with(
            ($intel['status'] ?? '') === 'partial' ? 'antiddos:error' : 'antiddos:success',
            ($intel['status'] ?? '') === 'partial'
                ? "No se pudo consultar el origen de {$data['ip']}: los servicios no respondieron. Se reintentará solo en unos minutos."
                : "Consulta de {$data['ip']} repetida: " . ($intel['verdict_text'] ?? 'listo') . '.'
        );
    }

    public function refreshLists(): RedirectResponse
    {
        @set_time_limit(120);

        $results = $this->reputation->refresh();
        $ok = collect($results)->where('ok', true)->count();

        return $this->tab('shield')->with(
            $ok > 0 ? 'antiddos:success' : 'antiddos:error',
            sprintf('Listas de reputación actualizadas: %d de %d descargadas%s.', $ok, count($results), $ok < count($results) ? ' (las que fallaron conservan la copia anterior)' : '')
        );
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

        ShieldSetting::write([
            'api_key_hash' => password_hash($key, PASSWORD_DEFAULT),
            'api_key_enc' => Crypt::encryptString($key),
        ]);
        ShieldEvent::record('apikey', 'Clave de la API regenerada', $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('nodes')
            ->with('antiddos:success', 'Clave nueva generada. A partir de ahora puedes verla y copiarla cuando quieras en esta pantalla. Los nodos con la clave anterior dejarán de sincronizar hasta que la actualices en ellos.')
            ->with('antiddos:newkey', $key);
    }

    public function clearEvents(): RedirectResponse
    {
        ShieldEvent::query()->delete();

        return $this->tab('events')
            ->with('antiddos:success', 'Registro de eventos vaciado.');
    }
}
