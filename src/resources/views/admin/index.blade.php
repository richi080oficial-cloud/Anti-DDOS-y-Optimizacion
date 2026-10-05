@extends('layouts.admin')

@section('title')
    Anti-DDoS y Optimización
@endsection

@section('content-header')
    <h1>Anti-DDoS y Optimización <small>v{{ $version }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Anti-DDoS y Optimización</li>
    </ol>
@endsection

@section('content')
    @php
        $matchedIds = collect($health['nodes'])->pluck('agent.id')->filter()->all();
        $otherAgents = $health['agents']->reject(fn ($a) => in_array($a->id, $matchedIds, true));
        $installCmd = 'bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) node-install --tcp=' . ($fw['tcp'] ?: '25565') . ' --panel-url=' . ($panelUrl ?: 'https://tu-panel') . ' --key=' . (session('antiddos:newkey') ?: 'TU_CLAVE');
    @endphp

    <style>
        /* Sin colores de fondo fijos: hereda el tema activo (claro, oscuro o de terceros). */
        .ad, .ad * { box-sizing: border-box; }
        .ad { width: 100%; max-width: 100%; clear: both; --ok: #00a65a; --warn: #f39c12; --err: #dd4b39; --pri: #3c8dbc; --line: rgba(127,127,127,.28); }
        .ad .card { border: 1px solid var(--line); border-radius: 6px; margin-bottom: 16px; }
        .ad .card-h { padding: 12px 16px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .ad .card-h h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .ad .card-b { padding: 16px; }
        .ad .card-f { padding: 12px 16px; border-top: 1px solid var(--line); }
        .ad .muted { opacity: .7; }
        .ad .small { font-size: 12px; }
        .ad code, .ad pre { background: rgba(127,127,127,.15); color: inherit; border: 0; border-radius: 3px; }
        .ad pre { padding: 10px 12px; margin: 8px 0; white-space: pre-wrap; word-break: break-all; }
        .ad pre .copy { margin-left: 8px; }
        .ad .hero { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; padding: 18px 20px; border-left: 6px solid var(--line); }
        .ad .hero.ok { border-left-color: var(--ok); } .ad .hero.warn { border-left-color: var(--warn); } .ad .hero.error { border-left-color: var(--err); }
        .ad .hero .ico { font-size: 34px; line-height: 1; }
        .ad .hero .txt { flex: 1 1 280px; min-width: 0; }
        .ad .hero .txt h2 { margin: 0 0 4px; font-size: 20px; }
        .ad .tabs { display: flex; flex-wrap: wrap; gap: 2px; margin: 0 0 16px; padding: 0; list-style: none; border-bottom: 1px solid var(--line); }
        .ad .tabs a { display: block; padding: 10px 16px; cursor: pointer; color: inherit; opacity: .7; border-bottom: 3px solid transparent; text-decoration: none; }
        .ad .tabs a:hover { opacity: 1; } .ad .tabs a.active { opacity: 1; font-weight: 600; border-bottom-color: var(--pri); }
        .ad .pane { display: none; } .ad .pane.active { display: block; }
        .ad .grid { display: flex; flex-wrap: wrap; gap: 16px; }
        .ad .col { flex: 1 1 320px; min-width: 0; } .ad .col-2 { flex: 2 1 480px; min-width: 0; }
        .ad .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 0; background: var(--line); flex: 0 0 10px; }
        .ad .dot.ok { background: var(--ok); } .ad .dot.warn { background: var(--warn); } .ad .dot.error { background: var(--err); }
        .ad .row-i { display: flex; gap: 10px; padding: 11px 0; border-bottom: 1px solid var(--line); align-items: flex-start; }
        .ad .row-i:last-child { border-bottom: 0; }
        .ad .row-i .dot { margin-top: 6px; }
        .ad .row-i .body { flex: 1; min-width: 0; }
        .ad .row-i .body b { display: block; }
        .ad .fix { margin-top: 5px; }
        .ad .pill { display: inline-block; padding: 1px 9px; border-radius: 10px; font-size: 12px; border: 1px solid var(--line); }
        .ad .pill.ok { color: var(--ok); border-color: var(--ok); } .ad .pill.warn { color: var(--warn); border-color: var(--warn); } .ad .pill.error { color: var(--err); border-color: var(--err); }
        .ad .f { margin-bottom: 14px; } .ad .f label { display: block; font-weight: 600; margin-bottom: 3px; } .ad .f small { display: block; opacity: .7; margin-top: 3px; }
        .ad .f-row { display: flex; flex-wrap: wrap; gap: 14px; } .ad .f-row .f { flex: 1 1 180px; min-width: 0; }
        .ad .check { display: flex; align-items: flex-start; gap: 9px; margin: 8px 0; font-weight: normal; cursor: pointer; }
        .ad .check input { margin: 3px 0 0; } .ad .check span small { display: block; opacity: .7; }
        .ad .table { width: 100%; color: inherit; background: transparent; margin: 0; }
        .ad .table > thead > tr > th, .ad .table > tbody > tr > td { border-color: var(--line); vertical-align: middle; padding: 8px; }
        .ad .tw { overflow-x: auto; }
        .ad details { border: 1px solid var(--line); border-radius: 6px; padding: 10px 14px; margin: 12px 0; }
        .ad details > summary { cursor: pointer; font-weight: 600; }
        .ad .alert { color: inherit; background: rgba(127,127,127,.12); border: 1px solid var(--line); border-left-width: 5px; border-radius: 4px; padding: 11px 14px; margin-bottom: 14px; }
        .ad .alert.s { border-left-color: var(--ok); } .ad .alert.e { border-left-color: var(--err); } .ad .alert.w { border-left-color: var(--warn); }
        .ad .atk { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .ad .atk .state { font-weight: 700; font-size: 15px; }
        .ad .kpis { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .ad .kpi { flex: 1 1 150px; border: 1px solid var(--line); border-radius: 6px; padding: 12px 14px; }
        .ad .kpi .n { font-size: 24px; font-weight: 700; line-height: 1.15; }
        .ad .kpi .l { font-size: 12px; opacity: .7; }
        .ad .seg { display: inline-flex; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; }
        .ad .seg button { border: 0; background: transparent; color: inherit; padding: 6px 14px; cursor: pointer; opacity: .75; }
        .ad .seg button.on { background: rgba(60,141,188,.2); opacity: 1; font-weight: 600; }
        .ad .chart-wrap { position: relative; width: 100%; }
        .ad #ad-chart { width: 100%; height: 320px; display: block; }
        .ad .tip { position: absolute; pointer-events: none; display: none; z-index: 5; padding: 8px 10px; border-radius: 6px; font-size: 12px; line-height: 1.5; background: rgba(20,20,20,.92); color: #fff; white-space: nowrap; }
        .ad .legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; }
        .ad .legend label { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: normal; cursor: pointer; }
        .ad .legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; }
        .ad .issue { margin: 2px 0; font-size: 12.5px; }
        .ad .portform { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .ad .portform input[type=number] { width: 100px; }
    </style>

    <div class="ad">
        @if (session('antiddos:success'))<div class="alert s">{{ session('antiddos:success') }}</div>@endif
        @if (session('antiddos:error'))<div class="alert e">{{ session('antiddos:error') }}</div>@endif
        @if ($errors->any())
            <div class="alert e">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        @endif
        @if (session('antiddos:newkey'))
            <div class="alert w"><b>Nueva clave de la API</b> (cópiala ahora, no se vuelve a mostrar):<pre>{{ session('antiddos:newkey') }}</pre></div>
        @endif

        {{-- ─────────── Cabecera de estado ─────────── --}}
        <div class="card hero {{ $health['level'] }}">
            <div class="ico">{{ ['ok' => '🛡️', 'warn' => '⚠️', 'error' => '🔧'][$health['level']] }}</div>
            <div class="txt">
                <h2>{{ $health['title'] }}</h2>
                <div class="muted">{{ $health['text'] }}</div>
            </div>
            <div class="atk">
                <div>
                    <div class="small muted">Modo «bajo ataque»</div>
                    <div class="state" style="color: var(--{{ $fw['attack'] ? 'err' : 'ok' }})">{{ $fw['attack'] ? 'ACTIVADO' : 'Desactivado' }}</div>
                </div>
                <form action="{{ route('admin.extensions.antiddos.attack') }}" method="POST">
                    @csrf
                    <input type="hidden" name="mode" value="{{ $fw['attack'] ? 'off' : 'on' }}">
                    <button class="btn btn-{{ $fw['attack'] ? 'default' : 'danger' }}" type="submit">{{ $fw['attack'] ? 'Volver a normal' : 'Activar' }}</button>
                </form>
            </div>
        </div>

        <ul class="tabs" id="ad-tabs">
            <li><a data-tab="status">Estado</a></li>
            <li><a data-tab="traffic">Tráfico</a></li>
            <li><a data-tab="shield">Protección</a></li>
            <li><a data-tab="ports">Puertos @if ($ports['counts']['error'] + $ports['counts']['warn'] > 0)<span class="pill {{ $ports['counts']['error'] > 0 ? 'error' : 'warn' }}">{{ $ports['counts']['error'] + $ports['counts']['warn'] }}</span>@endif</a></li>
            <li><a data-tab="blocks">Bloqueos ({{ $blocks->count() }})</a></li>
            <li><a data-tab="events">Eventos</a></li>
        </ul>

        {{-- ═══════════════════════ ESTADO ═══════════════════════ --}}
        <div class="pane" id="pane-status">
            <div class="grid">
                <div class="col-2">
                    <div class="card">
                        <div class="card-h"><h3>¿Está todo instalado?</h3>
                            <span class="pill {{ $health['level'] }}">{{ $health['errors'] }} pendiente(s) · {{ $health['warnings'] }} aviso(s)</span></div>
                        <div class="card-b">
                            <div class="small muted" style="margin-bottom:6px;">PANEL</div>
                            @foreach ($health['checks'] as $c)
                                <div class="row-i">
                                    <span class="dot {{ $c['level'] }}"></span>
                                    <div class="body">
                                        <b>{{ $c['label'] }}</b>
                                        <span class="muted">{{ $c['detail'] }}</span>
                                        @if ($c['fix'])<div class="fix"><code>{{ $c['fix'] }}</code></div>@endif
                                    </div>
                                </div>
                            @endforeach

                            <div class="small muted" style="margin:16px 0 6px;">NODOS (FIREWALL ANTI-DDoS)</div>
                            @forelse ($health['nodes'] as $n)
                                <div class="row-i">
                                    <span class="dot {{ $n['level'] }}"></span>
                                    <div class="body">
                                        <b>{{ $n['name'] }} <span class="muted small">{{ $n['fqdn'] }}</span></b>
                                        <span class="muted">{{ $n['detail'] }}</span>
                                        @if ($n['fix'])<div class="fix"><code>{{ $n['fix'] }}</code></div>@endif
                                    </div>
                                </div>
                            @empty
                                <div class="muted">Este panel no tiene nodos creados todavía.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card">
                        <div class="card-h"><h3>Añadir un nodo</h3></div>
                        <div class="card-b">
                            <p class="muted">En cada máquina con Wings que <b>no sea</b> este panel, ejecuta como root:</p>
                            <pre>{{ $installCmd }}<button type="button" class="btn btn-xs btn-default copy">Copiar</button></pre>
                            <p class="muted small" style="margin:0;">Si el panel y Wings están en la misma máquina no hace falta: ya se instaló con <code>install-all</code>. La clave se muestra al regenerarla en la pestaña Protección.</p>
                        </div>
                    </div>
                </div>
            </div>

            @if ($health['agents']->isNotEmpty())
                <div class="card">
                    <div class="card-h"><h3>Agentes conectados</h3><span class="muted small">Se actualizan cada ~30 s</span></div>
                    <div class="tw">
                        <table class="table">
                            <thead><tr><th>Máquina</th><th>Estado</th><th>Puertos TCP</th><th>UDP</th><th>Límite/IP</th><th>Bloqueadas</th><th>Paquetes frenados</th><th>Versión</th><th>Visto</th></tr></thead>
                            <tbody>
                            @foreach ($health['agents'] as $a)
                                @php($p = $a->payload ?? [])
                                <tr>
                                    <td><b>{{ $a->host }}</b><div class="muted small">{{ implode(', ', array_slice((array) $a->ips, 0, 2)) }}{{ ($p['mode'] ?? '') === 'local' ? ' · mismo servidor que el panel' : '' }}</div></td>
                                    <td>@if ($a->isOnline() && ($p['active'] ?? false))<span class="pill ok">Activo</span>@elseif ($a->isOnline())<span class="pill error">Reglas apagadas</span>@else<span class="pill warn">Sin respuesta</span>@endif
                                        @if ($p['attack'] ?? false)<span class="pill error">Bajo ataque</span>@endif</td>
                                    <td>{{ ($p['tcp'] ?? '') ?: '—' }}</td>
                                    <td>{{ ($p['udp'] ?? '') ?: '—' }}</td>
                                    <td>{{ $p['conn_limit'] ?? '—' }}</td>
                                    <td>{{ ($p['bans_local'] ?? 0) + ($p['bans_panel'] ?? 0) }}</td>
                                    <td>{{ number_format($p['dropped'] ?? 0, 0, ',', '.') }}</td>
                                    <td>{{ $a->version }}</td>
                                    <td>{{ $a->last_seen_at ? $a->last_seen_at->diffForHumans() : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @if ($otherAgents->isNotEmpty())
                <div class="alert w">Hay {{ $otherAgents->count() }} agente(s) conectado(s) que no se han podido asociar a ningún nodo del panel (suele pasar si el FQDN del nodo apunta a una IP distinta a la del servidor). Funcionan igualmente.</div>
            @endif
        </div>

        {{-- ═══════════════════════ PROTECCIÓN ═══════════════════════ --}}
        <div class="pane" id="pane-shield">
            <div class="grid">
                <div class="col-2">
                    <form action="{{ route('admin.extensions.antiddos.firewall.update') }}" method="POST" class="card">
                        @csrf
                        @method('PATCH')
                        <div class="card-h"><h3>Firewall de los nodos</h3>
                            <span class="pill {{ $fw['managed'] ? 'ok' : 'warn' }}">{{ $fw['managed'] ? 'Gestionado desde el panel' : 'Aún sin gestionar' }}</span></div>
                        <div class="card-b">
                            @unless ($fw['managed'])
                                <div class="alert w" style="margin-bottom:14px;">Se muestran los valores que ya tiene el nodo. Al guardar, el panel pasa a controlarlos y los envía a <b>todos</b> los nodos.</div>
                            @endunless

                            <label class="check"><input type="checkbox" name="auto" value="1" {{ old('auto', $fw['auto']) ? 'checked' : '' }}>
                                <span>Detectar automáticamente los servidores de Minecraft y proteger sus puertos
                                    <small>El panel reconoce los eggs de Minecraft (Paper, Spigot, Forge, Bedrock, BungeeCord…), toma el puerto de cada servidor y lo protege solo. Si creas, mueves o borras un servidor, se actualiza en menos de un minuto.</small></span></label>
                            <label class="check" style="margin-left:26px;"><input type="checkbox" name="extra" value="1" {{ old('extra', $fw['extra']) ? 'checked' : '' }}>
                                <span>Proteger también los puertos adicionales de esos servidores
                                    <small>Dynmap, voice chat, query… (todo lo que no es el puerto principal). Es más seguro: la IP del servidor queda protegida en todos sus puertos.</small></span></label>

                            <div class="alert {{ $mc['plan']['tcp'] !== '' || $mc['plan']['udp'] !== '' ? 's' : 'w' }}" style="margin:12px 0;">
                                @if ($fw['managed'] && $fw['auto'])
                                    <b>Ahora se protegen</b> ({{ $mc['plan']['servers'] }} servidor(es) de Minecraft detectado(s)):<br>
                                    TCP <code>{{ $mc['plan']['tcp'] ?: '—' }}</code> · UDP <code>{{ $mc['plan']['udp'] ?: '—' }}</code>
                                @elseif ($fw['auto'])
                                    <b>Se han detectado {{ $mc['eggs']->where('selected', true)->sum('servers') }} servidor(es) de Minecraft.</b>
                                    Al guardar se protegerán sus puertos: TCP <code>{{ $mc['plan']['auto_tcp'] ?: '—' }}</code> · UDP <code>{{ $mc['plan']['auto_udp'] ?: '—' }}</code>
                                @else
                                    Detección automática desactivada: solo se protegen los puertos manuales de abajo.
                                @endif
                            </div>

                            <details>
                                <summary>Eggs que cuentan como Minecraft ({{ $mc['eggs']->where('selected', true)->count() }} marcados)</summary>
                                <p class="muted small" style="margin:10px 0;">Marcados por defecto los que el panel reconoce por su nombre. Marca o desmarca los que quieras; los de Bedrock/PocketMine/Nukkit/Geyser protegen su puerto por UDP.</p>
                                <input type="hidden" name="eggs_present" value="1">
                                @foreach ($mc['eggs'] as $egg)
                                    <label class="check"><input type="checkbox" name="eggs[]" value="{{ $egg['id'] }}" {{ $egg['selected'] ? 'checked' : '' }}>
                                        <span>{{ $egg['name'] }} <span class="muted small">· {{ $egg['nest'] }} · {{ $egg['servers'] }} servidor(es){{ $egg['bedrock'] ? ' · UDP' : '' }}</span></span></label>
                                @endforeach
                            </details>

                            <div class="f-row" style="margin-top:12px;">
                                <div class="f">
                                    <label for="tcp">Puertos TCP manuales (opcional)</label>
                                    <input class="form-control" id="tcp" name="tcp" value="{{ old('tcp', $fw['tcp']) }}" placeholder="25565:25666">
                                    <small>Se suman a los detectados. Un puerto (<code>25565</code>), un rango (<code>25565:25666</code>) o varios con comas.</small>
                                </div>
                                <div class="f">
                                    <label for="udp">Puertos UDP manuales (opcional)</label>
                                    <input class="form-control" id="udp" name="udp" value="{{ old('udp', $fw['udp']) }}" placeholder="19132">
                                    <small>Para Bedrock/Geyser u otros servicios UDP.</small>
                                </div>
                            </div>
                            <details>
                                <summary>Límites por IP (avanzado)</summary>
                                <div class="f-row" style="margin-top:12px;">
                                    <div class="f"><label for="conn_limit">Conexiones simultáneas</label>
                                        <input class="form-control" type="number" id="conn_limit" name="conn_limit" min="3" max="1000" value="{{ old('conn_limit', $fw['conn_limit']) }}">
                                        <small>Máx. por IP. Súbelo si hay colegios/cibers con una sola IP.</small></div>
                                    <div class="f"><label for="syn_rate">Conexiones nuevas por segundo</label>
                                        <input class="form-control" type="number" id="syn_rate" name="syn_rate" min="2" max="1000" value="{{ old('syn_rate', $fw['syn_rate']) }}">
                                        <small>Si una IP supera esto, se bloquea.</small></div>
                                    <div class="f"><label for="udp_rate">Paquetes UDP por segundo</label>
                                        <input class="form-control" type="number" id="udp_rate" name="udp_rate" min="20" max="100000" value="{{ old('udp_rate', $fw['udp_rate']) }}">
                                        <small>Solo descarta el exceso.</small></div>
                                    <div class="f"><label for="ban_seconds">Duración del bloqueo (s)</label>
                                        <input class="form-control" type="number" id="ban_seconds" name="ban_seconds" min="30" max="604800" value="{{ old('ban_seconds', $fw['ban_seconds']) }}">
                                        <small>900 = 15 minutos.</small></div>
                                </div>
                            </details>
                            <p class="muted small" style="margin:0;">No incluyas aquí el puerto del panel (80/443) ni el de SSH: se gestionan aparte.</p>
                        </div>
                        <div class="card-f"><button class="btn btn-primary" type="submit">Guardar y enviar a los nodos</button></div>
                    </form>
                </div>

                <div class="col">
                    <div class="card">
                        <div class="card-h"><h3>Modo «bajo ataque»</h3></div>
                        <div class="card-b">
                            <p class="muted">Endurece los límites de todos los nodos de golpe (conexiones y ráfagas ÷3, bloqueos ×4). Úsalo cuando notes un ataque y desactívalo al terminar.</p>
                            <form action="{{ route('admin.extensions.antiddos.attack') }}" method="POST">
                                @csrf
                                <input type="hidden" name="mode" value="{{ $fw['attack'] ? 'off' : 'on' }}">
                                <button class="btn btn-{{ $fw['attack'] ? 'default' : 'danger' }} btn-block" type="submit">{{ $fw['attack'] ? 'Desactivar modo bajo ataque' : 'Activar modo bajo ataque' }}</button>
                            </form>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-h"><h3>Clave de la API</h3><span class="pill {{ $hasApiKey ? 'ok' : 'warn' }}">{{ $hasApiKey ? 'Creada' : 'Sin crear' }}</span></div>
                        <div class="card-b">
                            <p class="muted">Solo la usan los nodos que están en <b>otra máquina</b>. Al regenerarla, los nodos con la anterior dejan de sincronizar hasta actualizarla.</p>
                            <form action="{{ route('admin.extensions.antiddos.apikey') }}" method="POST" onsubmit="return confirm('Se generará una clave nueva y los nodos con la anterior dejarán de sincronizar. ¿Continuar?');">
                                @csrf
                                <button class="btn btn-warning btn-block" type="submit">{{ $hasApiKey ? 'Regenerar clave' : 'Generar clave' }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <form action="{{ route('admin.extensions.antiddos.settings.update') }}" method="POST" class="card">
                @csrf
                @method('PATCH')
                <div class="card-h"><h3>Escudo web del panel</h3>
                    <span class="pill {{ $settings['enabled'] ? 'ok' : 'warn' }}">{{ $settings['enabled'] ? 'Activo' : 'Apagado' }}</span></div>
                <div class="card-b">
                    <label class="check"><input type="checkbox" name="enabled" value="1" {{ $settings['enabled'] ? 'checked' : '' }}>
                        <span>Activar el escudo web<small>Limita las peticiones por IP al panel y bloquea abusos y fuerza bruta del login.</small></span></label>
                    <div class="f-row" style="margin-top:12px;">
                        <div class="f"><label for="general_limit">Peticiones por minuto</label>
                            <input class="form-control" type="number" id="general_limit" name="general_limit" min="30" max="100000" value="{{ old('general_limit', $settings['general_limit']) }}">
                            <small>Por IP. No cuentan los archivos estáticos ni Wings.</small></div>
                        <div class="f"><label for="auth_limit">Intentos de login por minuto</label>
                            <input class="form-control" type="number" id="auth_limit" name="auth_limit" min="3" max="1000" value="{{ old('auth_limit', $settings['auth_limit']) }}">
                            <small>Por IP.</small></div>
                        <div class="f"><label for="ban_minutes">Primer bloqueo (minutos)</label>
                            <input class="form-control" type="number" id="ban_minutes" name="ban_minutes" min="1" max="10080" value="{{ old('ban_minutes', $settings['ban_minutes']) }}">
                            <small>Se duplica con cada reincidencia.</small></div>
                        <div class="f"><label for="ban_max_minutes">Bloqueo máximo (minutos)</label>
                            <input class="form-control" type="number" id="ban_max_minutes" name="ban_max_minutes" min="1" max="43200" value="{{ old('ban_max_minutes', $settings['ban_max_minutes']) }}">
                            <small>1440 = 24 horas.</small></div>
                    </div>
                    <div class="f">
                        <label for="whitelist">Lista blanca (nunca se bloquea)</label>
                        <textarea class="form-control" id="whitelist" name="whitelist" rows="4" placeholder="203.0.113.7&#10;198.51.100.0/24">{{ old('whitelist', $settings['whitelist']) }}</textarea>
                        <small>Una IP o rango por línea; también se aplica a los nodos. Tu IP actual: <code>{{ $myIp }}</code></small>
                    </div>
                    <details>
                        <summary>Más opciones</summary>
                        <div class="f-row" style="margin-top:12px;">
                            <div class="f"><label for="retention_days">Días que se guardan los eventos</label>
                                <input class="form-control" type="number" id="retention_days" name="retention_days" min="1" max="365" value="{{ old('retention_days', $settings['retention_days']) }}"></div>
                        </div>
                        <label class="check"><input type="checkbox" name="log_events" value="1" {{ $settings['log_events'] ? 'checked' : '' }}><span>Registrar eventos</span></label>
                    </details>
                </div>
                <div class="card-f"><button class="btn btn-primary" type="submit">Guardar escudo web</button></div>
            </form>
        </div>

        {{-- ═══════════════════════ TRÁFICO ═══════════════════════ --}}
        <div class="pane" id="pane-traffic">
            <div class="card">
                <div class="card-h">
                    <h3>Tráfico hacia tus servidores</h3>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <select id="ad-host" class="form-control" style="width:auto;">
                            <option value="">Todos los nodos</option>
                            @foreach ($metricsHosts as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                        </select>
                        <div class="seg" id="ad-range">
                            <button type="button" data-r="live" class="on">En vivo</button>
                            <button type="button" data-r="1h">1 h</button>
                            <button type="button" data-r="6h">6 h</button>
                            <button type="button" data-r="24h">24 h</button>
                            <button type="button" data-r="7d">7 días</button>
                        </div>
                    </div>
                </div>
                <div class="card-b">
                    <div class="kpis">
                        <div class="kpi"><div class="n" id="k-dropped" style="color:var(--err)">—</div><div class="l">Frenados en el periodo</div></div>
                        <div class="kpi"><div class="n" id="k-peak">—</div><div class="l">Pico de frenados / min</div></div>
                        <div class="kpi"><div class="n" id="k-syn" style="color:var(--pri)">—</div><div class="l">Conexiones nuevas</div></div>
                        <div class="kpi"><div class="n" id="k-tcp" style="color:var(--ok)">—</div><div class="l">TCP recibidos</div></div>
                        <div class="kpi"><div class="n" id="k-udp" style="color:var(--warn)">—</div><div class="l">UDP recibidos</div></div>
                        <div class="kpi"><div class="n" id="k-bans">—</div><div class="l">IPs bloqueadas ahora</div></div>
                    </div>
                    <div id="ad-live" class="small" style="margin:0 0 8px;"></div>
                    <div class="chart-wrap">
                        <canvas id="ad-chart"></canvas>
                        <div class="tip" id="ad-tip"></div>
                    </div>
                    <div class="legend" id="ad-legend">
                        <label><input type="checkbox" data-s="dropped" checked><i style="background:#dd4b39"></i> Frenados</label>
                        <label><input type="checkbox" data-s="syn" checked><i style="background:#3c8dbc"></i> Conexiones nuevas</label>
                        <label><input type="checkbox" data-s="tcp"><i style="background:#00a65a"></i> TCP</label>
                        <label><input type="checkbox" data-s="udp"><i style="background:#f39c12"></i> UDP</label>
                    </div>
                    <p class="muted small" id="ad-empty" style="margin:12px 0 0; display:none;"></p>
                    <p class="muted small" style="margin:12px 0 0;">
                        <b>En vivo</b> muestra los últimos 5 minutos con un punto por segundo (se actualiza cada segundo mientras miras esta pestaña); los demás rangos usan el historial guardado. Cada punto es la <b>velocidad media real</b> del tramo, en paquetes por minuto (no se muestran tramos a medias ni se reparten huecos en picos falsos).
                        Los <b>huecos</b> de la línea significan que el agente no informó en ese momento.
                        <b>Frenados</b> cuenta todo lo que descarta el firewall (IPs bloqueadas, límites por IP, paquetes malformados, ping, SSH y web si están activados), no solo lo dirigido a Minecraft.
                        <b>Conexiones nuevas, TCP y UDP</b> cuentan solo el tráfico externo hacia tus puertos protegidos (sin la lista blanca ni el tráfico interno de Docker).
                        Con varios nodos, las velocidades se suman.
                    </p>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════ PUERTOS ═══════════════════════ --}}
        <div class="pane" id="pane-ports">
            @if (session('antiddos:report'))
                <div class="card">
                    <div class="card-h"><h3>Informe de la última operación</h3></div>
                    <div class="card-b">
                        @foreach (session('antiddos:report') as [$serverName, $level, $message])
                            <div class="row-i"><span class="dot {{ $level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'error') }}"></span>
                                <div class="body"><b>{{ $serverName }}</b><span class="muted">{{ $message }}</span></div></div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="kpis">
                <div class="kpi"><div class="n" style="color:var(--err)">{{ $ports['counts']['error'] }}</div><div class="l">Puertos a cambiar ya</div></div>
                <div class="kpi"><div class="n" style="color:var(--warn)">{{ $ports['counts']['warn'] }}</div><div class="l">Puertos mejorables</div></div>
                <div class="kpi"><div class="n" style="color:var(--ok)">{{ $ports['counts']['ok'] }}</div><div class="l">Puertos recomendados</div></div>
            </div>

            <div class="card">
                <div class="card-h"><h3>Puertos de tus servidores de Minecraft</h3>
                    <span class="muted small">Se revisa el puerto principal de cada servidor</span></div>
                <div class="card-b">
                    <details style="margin-top:0;">
                        <summary>¿Qué se considera un puerto poco recomendable?</summary>
                        <ul style="margin:10px 0 0; padding-left:18px;">
                            <li><b>Rojo:</b> puertos reservados (menores de 1024) o de servicios conocidos (SSH, web, MySQL, Redis, Wings…). Cámbialos ya.</li>
                            <li><b>Naranja:</b> el puerto por defecto (25565 / 19132) y su entorno (25500-25700), que es lo que más barren los bots; el rango típico de servicios web (8000-8999); los del rango de puertos temporales de Linux (32768+) y los que el firewall no está protegiendo.</li>
                            <li><b>Puerto recomendado:</b> uno alto y libre, por defecto entre 20000 y 32000 (puedes cambiar el rango arriba). Con la detección automática, el firewall protege el nuevo puerto solo.</li>
                        </ul>
                    </details>

                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:14px 0;">
                        <input type="text" id="ad-pfilter" class="form-control" style="max-width:240px;" placeholder="Buscar servidor…">
                        <label class="check" style="margin:0;"><input type="checkbox" id="ad-ponly"><span>Solo con problemas</span></label>
                        <label class="check" style="margin:0;"><input type="checkbox" id="ad-restart"><span>Reiniciar el servidor al cambiar (si está encendido)</span></label>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0 0 14px;">
                        <span>Puerto recomendado al azar entre</span>
                        <input type="number" id="ad-pmin" class="form-control input-sm" style="width:100px;" min="1024" max="65535" value="20000">
                        <span>y</span>
                        <input type="number" id="ad-pmax" class="form-control input-sm" style="width:100px;" min="1024" max="65535" value="32000">
                        <span class="muted small">(evita 8000-8999 y los puertos por defecto)</span>
                    </div>

                    <div class="alert w" style="margin-bottom:14px;">Al cambiar un puerto, <b>los jugadores tendrán que usar la dirección nueva</b>. Si usas un registro SRV o un proxy, actualízalo. El cambio surte efecto al reiniciar el servidor.</div>

                    <form id="ad-ports-bulk" action="{{ route('admin.extensions.antiddos.ports.auto') }}" method="POST">@csrf<input type="hidden" name="restart" value="0" id="ad-bulk-restart"><input type="hidden" name="min" value="20000" id="ad-bulk-min"><input type="hidden" name="max" value="32000" id="ad-bulk-max"></form>

                    <div class="tw">
                        <table class="table" id="ad-ports">
                            <thead><tr><th style="width:30px;"></th><th>Servidor</th><th>Nodo</th><th>Puerto</th><th>Valoración</th><th>Cambiar</th></tr></thead>
                            <tbody>
                            @forelse ($ports['rows'] as $row)
                                <tr data-name="{{ \Illuminate\Support\Str::lower($row['name'] . ' ' . $row['node']) }}" data-level="{{ $row['level'] }}">
                                    <td><input type="checkbox" name="servers[]" value="{{ $row['id'] }}" form="ad-ports-bulk" class="ad-psel"></td>
                                    <td><a href="{{ route('admin.servers.view', $row['id']) }}">{{ $row['name'] }}</a></td>
                                    <td>{{ $row['node'] }}</td>
                                    <td><code>{{ $row['ip'] }}:{{ $row['port'] }}</code> <span class="muted small">{{ $row['bedrock'] ? 'UDP' : 'TCP' }}</span>
                                        @if (count($row['extras']))<div class="muted small">+ adicionales: {{ implode(', ', $row['extras']) }}</div>@endif</td>
                                    <td>
                                        @if ($row['level'] === 'ok')
                                            <span class="pill ok">Recomendado</span>
                                        @else
                                            <span class="pill {{ $row['level'] }}">{{ $row['level'] === 'error' ? 'Cambiar ya' : 'Mejorable' }}</span>
                                            @foreach ($row['issues'] as $issue)<div class="issue">{{ $issue['level'] === 'error' ? '⛔' : '⚠️' }} {{ $issue['text'] }}</div>@endforeach
                                        @endif
                                    </td>
                                    <td>
                                        <form class="portform ad-port-form" action="{{ route('admin.extensions.antiddos.ports.change') }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="server_id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="restart" value="0">
                                            <input type="hidden" name="min" value="20000">
                                            <input type="hidden" name="max" value="32000">
                                            <input class="form-control input-sm" type="number" name="port" min="1024" max="65535" placeholder="Nuevo">
                                            <button class="btn btn-sm btn-primary" type="submit" data-confirm="¿Cambiar el puerto de este servidor?">Cambiar</button>
                                            <button class="btn btn-sm btn-default" type="submit" data-random="1" data-confirm="¿Cambiar a un puerto recomendado al azar?" title="Elige un puerto libre recomendado">🎲 Aleatorio</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="muted">No se han detectado servidores de Minecraft. Revisa en Protección qué eggs cuentan como Minecraft.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-f">
                    <button type="submit" form="ad-ports-bulk" class="btn btn-warning" id="ad-bulk-btn">Poner un puerto recomendado a los seleccionados</button>
                    <button type="button" class="btn btn-default" id="ad-psel-all">Marcar todos los visibles</button>
                    <button type="button" class="btn btn-default" id="ad-psel-bad">Marcar los de problema</button>
                    <button type="button" class="btn btn-default" id="ad-psel-none">Quitar marcas</button>
                    <span class="muted small" id="ad-pcount" style="margin-left:8px;">0 seleccionados</span>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════ BLOQUEOS ═══════════════════════ --}}
        <div class="pane" id="pane-blocks">
            <div class="grid">
                <div class="col">
                    <form action="{{ route('admin.extensions.antiddos.blocks.store') }}" method="POST" class="card">
                        @csrf
                        <div class="card-h"><h3>Bloquear una IP</h3></div>
                        <div class="card-b">
                            <div class="f"><label for="ban-ip">IP</label><input class="form-control" id="ban-ip" name="ip" placeholder="203.0.113.7" required></div>
                            <div class="f"><label for="ban-minutes">Minutos</label><input class="form-control" id="ban-minutes" type="number" name="minutes" min="1" placeholder="Vacío = permanente"></div>
                            <div class="f"><label for="ban-reason">Motivo</label><input class="form-control" id="ban-reason" name="reason" maxlength="200" placeholder="Opcional"></div>
                            <small class="muted">Llega a todos los nodos en menos de un minuto.</small>
                        </div>
                        <div class="card-f"><button class="btn btn-danger" type="submit">Bloquear</button></div>
                    </form>
                </div>
                <div class="col-2">
                    <div class="card">
                        <div class="card-h"><h3>IPs bloqueadas</h3></div>
                        <div class="tw">
                            <table class="table">
                                <thead><tr><th>IP</th><th>Origen</th><th>Caduca</th><th>Reinc.</th><th>Motivo</th><th></th></tr></thead>
                                <tbody>
                                @forelse ($blocks as $block)
                                    <tr>
                                        <td><code>{{ $block->ip }}</code></td>
                                        <td>{{ ['auto' => 'Escudo', 'manual' => 'Manual'][$block->source] ?? $block->source }}</td>
                                        <td>{{ $block->expires_at ? $block->expires_at->diffForHumans() : 'permanente' }}</td>
                                        <td>{{ $block->strikes }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit((string) $block->reason, 60) }}</td>
                                        <td>
                                            <form action="{{ route('admin.extensions.antiddos.blocks.destroy') }}" method="POST" style="display:inline;">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="ip" value="{{ $block->ip }}">
                                                <button class="btn btn-xs btn-default" type="submit">Desbloquear</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="muted">No hay IPs bloqueadas.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════ EVENTOS ═══════════════════════ --}}
        <div class="pane" id="pane-events">
            <div class="card">
                <div class="card-h"><h3>Últimos eventos</h3>
                    <form action="{{ route('admin.extensions.antiddos.events.clear') }}" method="POST" style="display:inline;" onsubmit="return confirm('¿Vaciar el registro de eventos?');">
                        @csrf @method('DELETE')<button class="btn btn-xs btn-default" type="submit">Vaciar</button>
                    </form>
                </div>
                <div class="tw">
                    <table class="table">
                        <thead><tr><th>Fecha</th><th>Tipo</th><th>IP</th><th>Detalle</th></tr></thead>
                        <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td style="white-space:nowrap;">{{ $event->created_at->format('Y-m-d H:i:s') }}</td>
                                <td><span class="pill {{ in_array($event->type, ['auto-ban', 'ban', 'attack'], true) ? 'error' : '' }}">{{ $event->type }}</span></td>
                                <td>{{ $event->ip ?: '—' }}</td>
                                <td>{{ $event->message }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted">Aún no hay eventos.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var $ = function (s, r) { return (r || document).querySelector(s); };
            var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

            var tabs = $$('#ad-tabs a');
            var names = tabs.map(function (a) { return a.getAttribute('data-tab'); });
            function show(name) {
                if (names.indexOf(name) === -1) { name = 'status'; }
                names.forEach(function (n) { $('#pane-' + n).classList.toggle('active', n === name); });
                tabs.forEach(function (a) { a.classList.toggle('active', a.getAttribute('data-tab') === name); });
                try { history.replaceState(null, '', '#' + name); } catch (e) {}
            }
            tabs.forEach(function (a) { a.addEventListener('click', function () { show(a.getAttribute('data-tab')); }); });
            show(location.hash.replace('#', '') || 'status');

            // Copiar comandos
            $$('.copy').forEach(function (b) {
                b.addEventListener('click', function () {
                    var clone = b.parentNode.cloneNode(true);
                    var btn = clone.querySelector('.copy'); if (btn) { btn.remove(); }
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(clone.textContent.trim()).then(function () {
                            b.textContent = '¡Copiado!'; setTimeout(function () { b.textContent = 'Copiar'; }, 1500);
                        });
                    }
                });
            });


            // ───────── Puertos ─────────
            var prow = $$('#ad-ports tbody tr[data-name]');
            function pfilter() {
                var q = ($('#ad-pfilter').value || '').toLowerCase().trim();
                var only = $('#ad-ponly').checked;
                prow.forEach(function (tr) {
                    var ok = (!q || tr.getAttribute('data-name').indexOf(q) !== -1) && (!only || tr.getAttribute('data-level') !== 'ok');
                    tr.style.display = ok ? '' : 'none';
                });
            }
            function pcount() { var c = $('#ad-pcount'); if (c) { c.textContent = $$('.ad-psel:checked').length + ' seleccionados'; } }
            if ($('#ad-pfilter')) {
                $('#ad-pfilter').addEventListener('input', pfilter);
                $('#ad-ponly').addEventListener('change', pfilter);
                pfilter();
                $$('.ad-psel').forEach(function (c) { c.addEventListener('change', pcount); });
                $('#ad-psel-none').addEventListener('click', function () { $$('.ad-psel').forEach(function (c) { c.checked = false; }); pcount(); });
                $('#ad-psel-all').addEventListener('click', function () {
                    prow.forEach(function (tr) { if (tr.style.display !== 'none') { $('.ad-psel', tr).checked = true; } }); pcount();
                });
                $('#ad-psel-bad').addEventListener('click', function () {
                    prow.forEach(function (tr) { if (tr.style.display !== 'none') { $('.ad-psel', tr).checked = tr.getAttribute('data-level') !== 'ok'; } }); pcount();
                });
                var clicked = null;
                $$('.ad-port-form button').forEach(function (b) { b.addEventListener('click', function () { clicked = b; }); });
                $$('.ad-port-form').forEach(function (f) {
                    f.addEventListener('submit', function (e) {
                        var random = clicked && clicked.getAttribute('data-random') === '1';
                        var msg = clicked ? clicked.getAttribute('data-confirm') : '';
                        if (!random && !$('input[name=port]', f).value) { e.preventDefault(); alert('Escribe el puerto nuevo o usa «Aleatorio».'); return; }
                        if (random) { $('input[name=port]', f).value = ''; }
                        if (msg && !confirm(msg + (random ? '' : ' Puerto: ' + $('input[name=port]', f).value))) { e.preventDefault(); return; }
                        $('input[name=restart]', f).value = $('#ad-restart').checked ? '1' : '0';
                        $('input[name=min]', f).value = $('#ad-pmin').value || '20000';
                        $('input[name=max]', f).value = $('#ad-pmax').value || '32000';
                    });
                });
                $('#ad-ports-bulk').addEventListener('submit', function (e) {
                    if (!$$('.ad-psel:checked').length) { e.preventDefault(); alert('Marca al menos un servidor.'); return; }
                    if (!confirm('Se cambiará el puerto de ' + $$('.ad-psel:checked').length + ' servidor(es) a uno recomendado al azar. Los jugadores tendrán que usar la dirección nueva. ¿Continuar?')) { e.preventDefault(); return; }
                    $('#ad-bulk-restart').value = $('#ad-restart').checked ? '1' : '0';
                    $('#ad-bulk-min').value = $('#ad-pmin').value || '20000';
                    $('#ad-bulk-max').value = $('#ad-pmax').value || '32000';
                });
            }

            // ───────── Gráfica de tráfico ─────────
            var cv = $('#ad-chart');
            if (cv) {
                var URL_TRAFFIC = @json(route('admin.extensions.antiddos.traffic'));
                var COLORS = { dropped: '#dd4b39', syn: '#3c8dbc', tcp: '#00a65a', udp: '#f39c12' };
                var NAMES = { dropped: 'Frenados', syn: 'Conexiones nuevas', tcp: 'TCP', udp: 'UDP' };
                var state = { range: 'live', host: '', data: null, vis: { dropped: true, syn: true, tcp: false, udp: false }, loadedAt: 0, tried: 0, loading: false, failing: false };

                function fmt(n) { return Math.round(n).toLocaleString('es-ES'); }
                function fmt1(n) { return n >= 100 ? fmt(n) : (Math.round(n * 10) / 10).toLocaleString('es-ES'); }
                function pad(n) { return (n < 10 ? '0' : '') + n; }
                function label(ts, long) {
                    var d = new Date(ts * 1000);
                    var hm = pad(d.getHours()) + ':' + pad(d.getMinutes()) + (state.range === 'live' ? ':' + pad(d.getSeconds()) : '');
                    return long || state.range === '7d' || state.range === '24h' ? pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + ' ' + hm : hm;
                }
                function ago(sec) {
                    if (sec < 90) { return Math.max(0, Math.round(sec)) + ' s'; }
                    if (sec < 5400) { return Math.round(sec / 60) + ' min'; }
                    return Math.round(sec / 3600) + ' h';
                }

                function draw(hover) {
                    var d = state.data; if (!d) { return; }
                    var dpr = window.devicePixelRatio || 1;
                    var W = cv.clientWidth, H = cv.clientHeight;
                    if (!W) { return; }
                    cv.width = W * dpr; cv.height = H * dpr;
                    var g = cv.getContext('2d'); g.scale(dpr, dpr); g.clearRect(0, 0, W, H);
                    var fg = getComputedStyle(cv).color || '#888';
                    var L = 60, R = 12, T = 12, B = 26, w = W - L - R, h = H - T - B, n = d.labels.length;

                    var max = 0;
                    Object.keys(state.vis).forEach(function (k) {
                        if (state.vis[k]) { d.series[k].forEach(function (v) { if (v !== null) { max = Math.max(max, v); } }); }
                    });
                    if (max < 1) { max = 1; }
                    var mag = Math.pow(10, Math.floor(Math.log(max) / Math.LN10)), step = mag;
                    [1, 2, 2.5, 5, 10].some(function (m) { step = m * mag; return step >= max / 4; });
                    var top = Math.max(step, Math.ceil(max / step) * step);

                    g.font = '11px sans-serif'; g.fillStyle = fg; g.strokeStyle = 'rgba(127,127,127,.25)'; g.lineWidth = 1;
                    g.textAlign = 'right'; g.textBaseline = 'middle';
                    for (var v = 0; v <= top + 0.001; v += step) {
                        var y = T + h - (v / top) * h;
                        g.beginPath(); g.moveTo(L, y); g.lineTo(W - R, y); g.stroke();
                        g.fillText(fmt1(v), L - 6, y);
                    }
                    g.save(); g.translate(12, T + h / 2); g.rotate(-Math.PI / 2); g.textAlign = 'center'; g.fillText('paquetes / min', 0, 0); g.restore();
                    g.textAlign = 'center'; g.textBaseline = 'top';
                    var ticks = Math.min(6, n);
                    for (var t = 0; t < ticks; t++) {
                        var idx = Math.round(t * (n - 1) / Math.max(1, ticks - 1));
                        g.fillText(label(d.labels[idx]), L + (n > 1 ? idx / (n - 1) : 0) * w, T + h + 6);
                    }
                    var X = function (i) { return L + (n > 1 ? i / (n - 1) : 0) * w; };
                    var Y = function (val) { return T + h - (val / top) * h; };

                    // Cada serie se dibuja por tramos continuos: un hueco (null) corta la linea.
                    Object.keys(state.vis).forEach(function (k) {
                        if (!state.vis[k]) { return; }
                        var vals = d.series[k], run = [];
                        function flush() {
                            if (!run.length) { return; }
                            g.beginPath();
                            run.forEach(function (i, j) { j ? g.lineTo(X(i), Y(vals[i])) : g.moveTo(X(i), Y(vals[i])); });
                            g.strokeStyle = COLORS[k]; g.lineWidth = 2; g.stroke();
                            if (run.length === 1) { g.fillStyle = COLORS[k]; g.beginPath(); g.arc(X(run[0]), Y(vals[run[0]]), 2.5, 0, 6.3); g.fill(); }
                            else {
                                g.lineTo(X(run[run.length - 1]), T + h); g.lineTo(X(run[0]), T + h); g.closePath();
                                g.globalAlpha = 0.10; g.fillStyle = COLORS[k]; g.fill(); g.globalAlpha = 1;
                            }
                            run = [];
                        }
                        vals.forEach(function (val, i) { if (val === null) { flush(); } else { run.push(i); } });
                        flush();
                    });

                    var tip = $('#ad-tip');
                    if (hover !== undefined && hover !== null && n > 0) {
                        var hx = X(hover);
                        g.strokeStyle = 'rgba(127,127,127,.6)'; g.beginPath(); g.moveTo(hx, T); g.lineTo(hx, T + h); g.stroke();
                        var html = '<b>' + label(d.labels[hover], true) + '</b>';
                        var any = false;
                        Object.keys(state.vis).forEach(function (k) {
                            if (!state.vis[k]) { return; }
                            var val = d.series[k][hover];
                            if (val !== null) { any = true; html += '<br><span style="color:' + COLORS[k] + '">●</span> ' + NAMES[k] + ': ' + fmt1(val) + ' / min'; }
                        });
                        if (!any) { html += '<br>Sin datos del agente en este momento'; }
                        tip.innerHTML = html; tip.style.display = 'block';
                        tip.style.left = Math.min(W - 230, Math.max(0, hx + 12)) + 'px'; tip.style.top = (T + 6) + 'px';
                    } else { tip.style.display = 'none'; }
                }

                function kpis() {
                    var t = state.data.totals;
                    $('#k-dropped').textContent = fmt(t.dropped); $('#k-peak').textContent = fmt(t.peak_dropped_per_min);
                    $('#k-syn').textContent = fmt(t.syn); $('#k-tcp').textContent = fmt(t.tcp); $('#k-udp').textContent = fmt(t.udp);
                    $('#k-bans').textContent = fmt(t.bans_now);
                    var empty = $('#ad-empty');
                    if (!state.data.has_data) {
                        empty.style.display = 'block';
                        empty.textContent = state.range === 'live'
                            ? 'Aún no llegan datos en vivo. Hace falta el servicio antiddos-live en el nodo (versión 1.6.0): actualízalo con «sudo antiddos update --force». Mientras tanto puedes usar los rangos de 1 h o más.'
                            : (state.data.last_sample
                                ? 'Todavía no hay suficientes datos en este periodo (hacen falta al menos dos muestras seguidas del agente). Espera un minuto.'
                                : 'Aún no hay datos. Los agentes envían sus contadores cada ~30 s; si el agente es anterior a la versión 1.4.0, actualízalo con: sudo antiddos update --force');
                    } else { empty.style.display = 'none'; }
                }

                // Estado «en directo»: dice la verdad sobre lo reciente que son los datos.
                function live() {
                    var el = $('#ad-live'), d = state.data;
                    if (!el) { return; }
                    // Edad calculada con la hora del SERVIDOR (no la del navegador) para no depender de relojes desajustados.
                    var age = d && d.last_sample ? (d.generated_at - d.last_sample) + (Date.now() - state.loadedAt) / 1000 : 0, html;
                    if (state.failing) {
                        html = '<span style="color:var(--err)">● No se pudo actualizar</span> · reintentando…';
                    } else if (!d) {
                        html = '<span class="muted">Cargando…</span>';
                    } else if (!d.last_sample) {
                        html = '<span style="color:var(--warn)">● Sin datos todavía</span>';
                    } else if (age > (state.range === 'live' ? 6 : 120)) {
                        html = '<span style="color:var(--err)">● Sin datos recientes del agente</span> · último dato hace ' + ago(age) + ' (el agente no está informando)';
                    } else {
                        html = '<span style="color:var(--ok)">● En directo</span> · último dato del agente hace ' + ago(age) + ' · se actualiza ' + (state.range === 'live' ? 'cada segundo' : 'cada 15 s');
                    }
                    el.innerHTML = html;
                }

                function load() {
                    if (state.loading) { return; }
                    state.loading = true; state.tried = Date.now();
                    var url = URL_TRAFFIC + '?range=' + encodeURIComponent(state.range) + (state.host ? '&host=' + encodeURIComponent(state.host) : '');
                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
                        .then(function (json) { state.data = json; state.loadedAt = Date.now(); state.failing = false; kpis(); draw(); live(); })
                        .catch(function () { state.failing = true; live(); })
                        .then(function () { state.loading = false; });
                }

                $$('#ad-range button').forEach(function (b) {
                    b.addEventListener('click', function () {
                        $$('#ad-range button').forEach(function (x) { x.classList.toggle('on', x === b); });
                        state.range = b.getAttribute('data-r'); load();
                    });
                });
                $('#ad-host').addEventListener('change', function () { state.host = this.value; load(); });
                $$('#ad-legend input').forEach(function (c) { c.addEventListener('change', function () { state.vis[c.getAttribute('data-s')] = c.checked; draw(); }); });
                cv.addEventListener('mousemove', function (e) {
                    if (!state.data) { return; }
                    var rect = cv.getBoundingClientRect(), n = state.data.labels.length;
                    var rel = (e.clientX - rect.left - 60) / Math.max(1, rect.width - 72);
                    draw(Math.max(0, Math.min(n - 1, Math.round(rel * (n - 1)))));
                });
                cv.addEventListener('mouseleave', function () { draw(); });
                window.addEventListener('resize', function () { draw(); });

                // Siempre al dia: se actualiza cada 15 s aunque estes en otra pestaña del panel
                // (asi al volver ya esta fresco) y se refresca al instante al volver a la ventana.
                tabs.forEach(function (a) { a.addEventListener('click', function () { if (a.getAttribute('data-tab') === 'traffic') { setTimeout(function () { load(); draw(); }, 30); } }); });
                document.addEventListener('visibilitychange', function () { if (!document.hidden) { load(); } });
                setInterval(function () {
                    if (document.hidden) { return; }
                    var watching = state.range === 'live' && $('#pane-traffic').classList.contains('active');
                    if (Date.now() - state.tried >= (watching ? 950 : 14800)) { load(); }
                }, 250);
                setInterval(live, 1000);
                load();
            }
        })();
    </script>
@endsection
