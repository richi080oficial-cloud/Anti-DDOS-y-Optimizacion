<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Pterodactyl\Extensions\AntiDDoS\Models\ServerOptimization;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;
use Pterodactyl\Extensions\AntiDDoS\Services\OptimizationProfiles;
use Pterodactyl\Extensions\AntiDDoS\Services\ServerOptimizer;
use Pterodactyl\Models\Server;

class AdminController extends Controller
{
    /** Servidores que se listan como maximo en la pestana de optimizacion. */
    protected const SERVER_LIST_LIMIT = 300;

    /** Servidores que se procesan como maximo por envio desde el navegador. */
    protected const OPTIMIZE_BATCH_LIMIT = 40;

    public function __construct(
        protected BlockManager $blocks,
        protected ServerOptimizer $optimizer,
    ) {
    }

    /** Vuelve a la pantalla de admin abriendo una pestana concreta (#ancla). */
    protected function tab(string $tab): RedirectResponse
    {
        return redirect(route('admin.extensions.antiddos.index') . '#' . $tab);
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $servers = Server::query()
            ->with(['egg', 'nest', 'node'])
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%' . $q . '%')->orWhere('uuidShort', 'like', $q . '%');
            }))
            ->orderBy('name')
            ->limit(2000)
            ->get()
            ->filter(fn (Server $s) => $this->optimizer->isMinecraft($s))
            ->take(self::SERVER_LIST_LIMIT)
            ->values();

        $states = ServerOptimization::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->get()
            ->keyBy('server_id');

        $settings = ShieldSetting::snapshot();

        return view('antiddos::admin.index', [
            'version' => trim((string) @file_get_contents(__DIR__ . '/../../../VERSION')) ?: 'dev',
            'settings' => $settings,
            'blocks' => $this->blocks->active(),
            'events' => ShieldEvent::query()->orderByDesc('id')->limit(150)->get(),
            'profiles' => OptimizationProfiles::all(),
            'servers' => $servers,
            'states' => $states,
            'search' => $q,
            'hasApiKey' => (string) (ShieldSetting::read('api_key_hash') ?? '') !== '',
            'panelUrl' => rtrim((string) config('app.url'), '/'),
            'myIp' => $request->ip(),
            'batchLimit' => self::OPTIMIZE_BATCH_LIMIT,
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
            'heap_percent' => 'required|integer|min:60|max:95',
            'default_profile' => 'required|in:' . implode(',', array_keys(OptimizationProfiles::all())),
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
            'heap_percent' => $data['heap_percent'],
            'default_profile' => $data['default_profile'],
            'whitelist' => implode("\n", $whitelist),
        ]);

        ShieldEvent::record('settings', 'Configuración del escudo actualizada', $request->ip(), ['user_id' => $request->user()?->id]);

        return $this->tab('shield')
            ->with('antiddos:success', 'Configuración guardada.');
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

    public function optimize(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'profile' => 'required|in:' . implode(',', array_keys(OptimizationProfiles::all())),
            'servers' => 'required|array|min:1',
            'servers.*' => 'integer|min:1',
        ]);

        return $this->process($request, $data['servers'], function (Server $server) use ($request, $data) {
            return $this->optimizer->apply(
                $server,
                $data['profile'],
                $request->boolean('flags'),
                $request->boolean('files'),
                $request->boolean('restart'),
                $request->user()?->id
            );
        }, 'optimizados');
    }

    public function revert(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'servers' => 'required|array|min:1',
            'servers.*' => 'integer|min:1',
        ]);

        return $this->process($request, $data['servers'], function (Server $server) use ($request) {
            return $this->optimizer->revert($server, $request->boolean('restart'), $request->user()?->id);
        }, 'revertidos');
    }

    public function clearEvents(): RedirectResponse
    {
        ShieldEvent::query()->delete();

        return $this->tab('events')
            ->with('antiddos:success', 'Registro de eventos vaciado.');
    }

    /**
     * Ejecuta una accion sobre varios servidores y deja un informe en sesion.
     *
     * @param array<int, int|string> $ids
     * @param callable(Server): array{ok: array<int, string>, warn: array<int, string>} $action
     */
    protected function process(Request $request, array $ids, callable $action, string $verb): RedirectResponse
    {
        @set_time_limit(300);

        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::OPTIMIZE_BATCH_LIMIT);
        $servers = Server::query()->with(['egg', 'nest', 'node'])->whereIn('id', $ids)->get();

        $lines = [];
        $done = 0;
        $failed = 0;

        foreach ($servers as $server) {
            try {
                $report = $action($server);
            } catch (\Throwable $e) {
                $failed++;
                $lines[] = [$server->name, 'error', Str::limit($e->getMessage(), 200)];

                continue;
            }

            $done++;

            foreach ($report['ok'] as $msg) {
                $lines[] = [$server->name, 'ok', $msg];
            }

            foreach ($report['warn'] as $msg) {
                $lines[] = [$server->name, 'warn', $msg];
            }
        }

        $message = sprintf('%d servidor(es) %s%s.', $done, $verb, $failed > 0 ? ", {$failed} con error" : '');

        if (count($request->input('servers', [])) > self::OPTIMIZE_BATCH_LIMIT) {
            $message .= sprintf(' Se procesaron solo los primeros %d; repite la operación para el resto o usa «antiddos optimize-server … --all» desde la consola.', self::OPTIMIZE_BATCH_LIMIT);
        }

        return $this->tab('optimize')
            ->with($failed > 0 && $done === 0 ? 'antiddos:error' : 'antiddos:success', $message)
            ->with('antiddos:report', array_slice($lines, 0, 120));
    }
}
