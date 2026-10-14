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
    @include('antiddos::admin.sidebar')
    @php
        $matchedIds = collect($health['nodes'])->pluck('agent.id')->filter()->all();
        $otherAgents = $health['agents']->reject(fn ($a) => in_array($a->id, $matchedIds, true));
        $ik = session('antiddos:newkey') ?: 'TU_CLAVE';
        $ipurl = $panelUrl ?: 'https://tu-panel';
        $itcp = $fw['tcp'] ?: '25565';
        $installCmd = 'd=$(mktemp -d) && curl -fsSL -H "X-Antiddos-Key: ' . $ik . '" ' . $ipurl . '/api/antiddos/agent-bundle'
            . ' | tar xz -C "$d" && bash "$d/scripts/node-agent.sh" install --tcp=' . $itcp . ' --panel-url=' . $ipurl . ' --key=' . $ik
            . ' && install -m 0755 "$d/antiddos.sh" /usr/local/bin/antiddos && rm -rf "$d"';
    @endphp

    <style>
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
        .ad .origin { white-space: nowrap; }
        .ad .net small { display: block; opacity: .7; }
        .ad tr.det td { background: rgba(127,127,127,.07); font-size: 12.5px; }
        .ad tr.det ul { margin: 4px 0 8px; padding-left: 18px; }
        .ad .link { cursor: pointer; text-decoration: underline; opacity: .8; }
        .ad .ctrl-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 12px; }
        .ad .ctrl-row label { margin: 0; font-weight: normal; font-size: 12px; display: flex; flex-direction: column; gap: 3px; opacity: .9; }
        .ad .kpi .l i { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
        .ad .kpi .sub { font-size: 11px; opacity: .65; margin-top: 2px; }
        .ad .legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; }
        .ad .legend label { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: normal; cursor: pointer; }
        .ad .legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; }
        .ad .issue { margin: 2px 0; font-size: 12.5px; }
        .ad .portform { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .ad .portform input[type=number] { width: 100px; }
        .ad .quick-asn { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 16px; border-left: 6px solid var(--err); }
        .ad .quick-asn .lbl { display: flex; flex-direction: column; flex: 0 1 240px; }
        .ad .quick-asn input[name=value] { flex: 1 1 180px; max-width: 280px; }
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

        <form action="{{ route('admin.extensions.antiddos.networks.store') }}" method="POST" class="card quick-asn"
              onsubmit="var v = this.querySelector('[name=value]').value.trim(); return v !== '' && confirm('¿Bloquear el operador ' + v + ' ENTERO (todas sus IPs, también las nuevas) en el panel y en todos los nodos?');">
            @csrf
            <input type="hidden" name="kind" value="asn">
            <input type="hidden" name="reason" value="Bloqueo rápido (ASN)">
            <div class="lbl">🚫 <b>Bloquear operador (ASN)</b><span class="muted small">Corta todas sus IPs, también las que use después</span></div>
            <input class="form-control" name="value" placeholder="AS199585" maxlength="120" required>
            <select class="form-control" name="hours" style="width:auto;">
                <option value="">Permanente</option>
                <option value="24">24 horas</option>
                <option value="168">7 días</option>
            </select>
            <button class="btn btn-danger" type="submit">Bloquear ASN</button>
            <a class="small" data-goto="asn" style="cursor:pointer;">{{ $netBlocks->count() }} bloqueado(s) · ver lista</a>
        </form>

        <ul class="tabs" id="ad-tabs">
            <li><a data-tab="status">Estado</a></li>
            <li><a data-tab="traffic">Tráfico</a></li>
            <li><a data-tab="shield">Protección</a></li>
            <li><a data-tab="ports">Puertos @if ($ports['counts']['error'] + $ports['counts']['warn'] > 0)<span class="pill {{ $ports['counts']['error'] > 0 ? 'error' : 'warn' }}">{{ $ports['counts']['error'] + $ports['counts']['warn'] }}</span>@endif</a></li>
            <li><a data-tab="asn">🚫 Operadores (ASN) ({{ $netBlocks->count() }})</a></li>
            <li><a data-tab="safe">🛡 IPs protegidas ({{ count($safeManual) }})</a></li>
            <li><a data-tab="blocks">Bloqueos de IPs ({{ $blocks->count() }})</a></li>
            <li><a data-tab="events">Eventos</a></li>
        </ul>

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
                            <p class="muted">Endurece los límites de todos los nodos de golpe, <b>solo para IPs desconocidas</b> (límite de conexiones y de conexiones nuevas a la mitad y, además, por red /24). Los jugadores que ya tienen una sesión establecida (se recuerdan 6 h), las redes compartidas, los proxies y la consola de Wings <b>conservan los límites normales</b>, así no se corta a nadie. Úsalo cuando notes un ataque y desactívalo al terminar.</p>
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

            <div class="grid">
                <div class="col">
                    <form action="{{ route('admin.extensions.antiddos.policy.update') }}" method="POST" class="card">
                        @csrf
                        @method('PATCH')
                        <div class="card-h"><h3>Política de bloqueo de IPs</h3>
                            <span class="pill {{ $banModeManaged ? 'ok' : 'warn' }}">{{ $banModeManaged ? 'Gestionada desde el panel' : 'La del nodo' }}</span></div>
                        <div class="card-b">
                            <p class="muted" style="margin-top:0;">Decide <b>cuándo</b> el firewall bloquea una IP por su cuenta. En todos los modos el exceso de conexiones se frena; lo que cambia es si además se banea.</p>
                            <label class="check"><input type="radio" name="ban_mode" value="monitor" {{ $banMode === 'monitor' ? 'checked' : '' }}>
                                <span><b>Observación</b> — nunca bloquea una IP<small>Solo frena el exceso de paquetes. Es el modo más seguro contra falsos positivos; úsalo para comprobar qué haría el firewall.</small></span></label>
                            <label class="check"><input type="radio" name="ban_mode" value="balanced" {{ $banMode === 'balanced' ? 'checked' : '' }}>
                                <span><b>Equilibrada (recomendada)</b> — bloquea solo ante un flood claro<small>El exceso normal solo se frena; se bloquea a partir de 3 veces el límite de conexiones nuevas (o 20 intentos/min en SSH).</small></span></label>
                            <label class="check"><input type="radio" name="ban_mode" value="strict" {{ $banMode === 'strict' ? 'checked' : '' }}>
                                <span><b>Estricta</b> — bloquea al primer exceso<small>Máxima dureza; puede bloquear a jugadores legítimos que comparten IP (colegios, cibers, móviles).</small></span></label>
                        </div>
                        <div class="card-f"><button class="btn btn-primary" type="submit">Guardar política</button></div>
                    </form>
                </div>

                <div class="col-2">
                    <form action="{{ route('admin.extensions.antiddos.intel.update') }}" method="POST" class="card">
                        @csrf
                        @method('PATCH')
                        <div class="card-h"><h3>Origen y reputación de IPs</h3>
                            <span class="pill {{ $settings['intel_enabled'] ? 'ok' : 'warn' }}">{{ $settings['intel_enabled'] ? 'Activado' : 'Desactivado' }}</span>
                            @if ($settings['intel_enabled'] && $settings['auto_block_malicious'])<span class="pill error">Auto-bloqueo: {{ ['24h' => '24 h', '7d' => '7 días', 'forever' => 'para siempre'][$settings['auto_block_duration']] ?? '24 h' }}</span>@endif</div>
                        <div class="card-b">
                            <label class="check"><input type="checkbox" name="intel_enabled" value="1" {{ $settings['intel_enabled'] ? 'checked' : '' }}>
                                <span>Consultar el origen y la reputación de las IPs
                                    <small>País, ciudad, proveedor y ASN (ipwho.is / ipapi.co) y listas públicas de atacantes (Blocklist.de, Spamhaus DROP, FireHOL Level 1, CINS Army, nodos Tor). Solo se envía la IP, nunca datos de jugadores. Si lo desactivas no se consulta nada.</small></span></label>
                            <div class="f" style="margin-top:12px; padding:12px 14px; border:1px solid var(--line); border-radius:6px; border-left:4px solid {{ $settings['auto_block_malicious'] ? 'var(--err)' : 'var(--line)' }};">
                                <label class="check" style="margin-top:0;"><input type="checkbox" name="auto_block_malicious" value="1" {{ $settings['auto_block_malicious'] ? 'checked' : '' }}>
                                    <span><b>Bloquear automáticamente las IPs maliciosas</b>
                                        <small>Cuando una IP con conexiones a tus puertos recibe el veredicto «Maliciosa» (aparece en listas públicas de atacantes o AbuseIPDB ≥ 75 %), se bloquea sola en el panel y en todos los nodos, sin esperar a que lo hagas tú. Las «Sospechosas» (Tor, servidores/VPN) <b>no</b> se bloquean solas. Requiere la consulta de reputación activada y el cron de Pterodactyl.</small></span></label>
                                <label for="auto_block_duration" style="margin-top:10px; display:block; font-weight:600;">Duración del bloqueo automático</label>
                                <select class="form-control" id="auto_block_duration" name="auto_block_duration" style="max-width:280px;">
                                    <option value="24h" {{ $settings['auto_block_duration'] === '24h' ? 'selected' : '' }}>24 horas (recomendado)</option>
                                    <option value="7d" {{ $settings['auto_block_duration'] === '7d' ? 'selected' : '' }}>7 días</option>
                                    <option value="forever" {{ $settings['auto_block_duration'] === 'forever' ? 'selected' : '' }}>Para siempre</option>
                                </select>
                                <small class="muted" style="display:block; margin-top:4px;">Con 24 h, si la IP sigue siendo maliciosa cuando caduque, se vuelve a bloquear sola. «Para siempre» deja la IP bloqueada hasta que la desbloquees tú (pestaña Bloqueos). Nunca se bloquean tus nodos, el panel, Cloudflare, la lista blanca ni tu IP.</small>
                            </div>
                            <div class="f" style="margin-top:12px;">
                                <label for="abuseipdb_key">Clave de AbuseIPDB (opcional)</label>
                                <input class="form-control" type="password" id="abuseipdb_key" name="abuseipdb_key" autocomplete="off" placeholder="{{ $abuseKeySet ? '•••••••• guardada (escribe otra para cambiarla)' : 'Pega aquí tu clave gratuita' }}">
                                <small>Añade el índice de abuso de AbuseIPDB (1.000 consultas gratis al día en abuseipdb.com). Se guarda cifrada con la clave del panel.</small>
                                @if ($abuseKeySet)<label class="check"><input type="checkbox" name="abuseipdb_clear" value="1"><span>Borrar la clave guardada</span></label>@endif
                            </div>
                        </div>
                        <div class="card-f"><button class="btn btn-primary" type="submit">Guardar</button></div>
                    </form>

                    <div class="card">
                        <div class="card-h"><h3>Listas públicas de reputación</h3>
                            <form action="{{ route('admin.extensions.antiddos.intel.refresh') }}" method="POST" style="display:inline;">@csrf<button class="btn btn-sm btn-default" type="submit">Actualizar ahora</button></form></div>
                        <div class="tw">
                            <table class="table">
                                <thead><tr><th>Lista</th><th>Entradas</th><th>Actualizada</th></tr></thead>
                                <tbody>
                                @foreach ($intelStatus as $list)
                                    <tr>
                                        <td>{{ $list['name'] }}</td>
                                        <td>{{ $list['loaded'] ? number_format($list['count'], 0, ',', '.') : '—' }}</td>
                                        <td>@if ($list['loaded'])<span class="muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($list['updated_at'])->diffForHumans() }}</span>@else<span class="pill warn">Sin descargar</span>@endif</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="card-f small muted">Se actualizan solas cada día a las 05:15 (hace falta el cron de Pterodactyl). También desde consola: <code>sudo antiddos ip 203.0.113.7</code> muestra el análisis completo de una IP.</div>
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
                            <small>Visitantes sin sesión, por IP. No cuentan los archivos estáticos ni Wings.</small></div>
                        <div class="f"><label for="user_limit">Peticiones por minuto (con sesión)</label>
                            <input class="form-control" type="number" id="user_limit" name="user_limit" min="120" max="200000" value="{{ old('user_limit', $settings['user_limit']) }}">
                            <small>Por usuario con sesión o clave de API válida. Pasarse da un aviso breve, no bloquea la IP.</small></div>
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

        <div class="pane" id="pane-traffic">
            <div class="card">
                <div class="card-h">
                    <h3>Tráfico de red</h3>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div class="seg" id="ad-view">
                            <button type="button" data-v="network" class="on">Red (↓ RX / ↑ TX)</button>
                            <button type="button" data-v="packets">Paquetes</button>
                            <button type="button" data-v="reasons">Motivos de frenado</button>
                        </div>
                        <div class="seg" id="ad-range">
                            <button type="button" data-r="live" class="on">En vivo</button>
                            <button type="button" data-r="1h">1 h</button>
                            <button type="button" data-r="6h">6 h</button>
                            <button type="button" data-r="24h">24 h</button>
                            <button type="button" data-r="7d">7 días</button>
                        </div>
                        <select id="ad-host" class="form-control" style="width:auto;">
                            <option value="">Todos los nodos</option>
                            @foreach ($metricsHosts as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="card-b">
                    <div class="ctrl-row">
                        <label>Unidad <select id="ad-unit" class="form-control input-sm"></select></label>
                        <label>Suavizado
                            <select id="ad-smooth" class="form-control input-sm">
                                <option value="1">Ninguno (dato real)</option>
                                <option value="5">Suave (media de 5 puntos)</option>
                                <option value="15">Muy suave (15 puntos)</option>
                            </select></label>
                        <label>Escala
                            <select id="ad-scale" class="form-control input-sm">
                                <option value="lin">Lineal</option>
                                <option value="log">Logarítmica (ver ataques y calma a la vez)</option>
                            </select></label>
                        <button type="button" class="btn btn-sm btn-default" id="ad-pause">⏸ Pausar</button>
                        <button type="button" class="btn btn-sm btn-default" id="ad-csv">⬇ CSV</button>
                    </div>
                    <div id="ad-live" class="small" style="margin:0 0 10px;"></div>
                    <div class="kpis" id="ad-stats"></div>
                    <div class="chart-wrap">
                        <canvas id="ad-chart"></canvas>
                        <div class="tip" id="ad-tip"></div>
                    </div>
                    <div class="legend" id="ad-legend"></div>
                    <p class="muted small" id="ad-empty" style="margin:12px 0 0; display:none;"></p>
                    <p class="muted small" id="ad-note" style="margin:12px 0 0;"></p>
                </div>
            </div>

            <div class="card">
                <div class="card-h">
                    <h3>Quién se está conectando</h3>
                    <span class="muted small">Mayores conexiones por IP externa hacia tus puertos protegidos · origen y reputación de listas públicas</span>
                </div>
                <div class="tw">
                    <table class="table" id="ad-top-table">
                        <thead><tr><th>Origen</th><th>IP</th><th>Red / proveedor</th><th>Conexiones</th><th>Veredicto</th><th style="width:1%;"></th></tr></thead>
                        <tbody id="ad-top"><tr><td colspan="6" class="muted">Cargando…</td></tr></tbody>
                    </table>
                </div>
                <div class="card-f small muted">
                    Se omiten las IPs de tus propios nodos y del panel. «Normal» = sin listados en bases de atacantes y red de operador/residencial (parece un jugador): <b>no la bloquees</b>.
                    «Sospechosa» = servidor/VPN/Tor o abuso moderado. «Maliciosa» = aparece en listas públicas de atacantes: bloquearla es seguro{{ $settings['intel_enabled'] && $settings['auto_block_malicious'] ? ' (y el bloqueo automático está activado: se bloquea sola)' : ' (puedes activar el bloqueo automático en Protección)' }}.
                    Las IPs de tus nodos, del panel, de Cloudflare, la lista blanca y la tuya <b>nunca se pueden bloquear</b>.
                </div>
            </div>

            <div class="card">
                <div class="card-h">
                    <h3>Bloqueos activos del firewall</h3>
                    <span class="muted small">IPs que el firewall de los nodos ha bloqueado por su cuenta · política: <b id="ad-banmode">—</b></span>
                </div>
                <div class="tw">
                    <table class="table">
                        <thead><tr><th>Origen</th><th>IP</th><th>Motivo del bloqueo</th><th>Queda</th><th>Veredicto</th><th style="width:1%;"></th></tr></thead>
                        <tbody id="ad-fwbans"><tr><td colspan="6" class="muted">Cargando…</td></tr></tbody>
                    </table>
                </div>
                <div class="card-f small muted">
                    Si una IP «Normal» aparece aquí, probablemente es un falso positivo (por ejemplo muchos jugadores tras una misma red): pulsa <b>«Es legítima»</b> para añadirla a la lista blanca y desbloquearla.
                    Si ves muchos falsos positivos, pon la política en <b>Equilibrada</b> u <b>Observación</b> en la pestaña Protección.
                </div>
            </div>
        </div>

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

        <div class="pane" id="pane-asn">
            <div class="grid">
                <div class="col">
                    <form action="{{ route('admin.extensions.antiddos.networks.store') }}" method="POST" class="card" id="ad-netform">
                        @csrf
                        <div class="card-h"><h3>Bloquear un operador o un país</h3></div>
                        <div class="card-b">
                            <p class="muted" style="margin-top:0;">Si te atacan desde un operador concreto (p. ej. <code>AS199585 · Operador / residencial</code> en la tabla «Quién se está conectando»), bloquéalo entero: se cortan <b>todas sus IPs, también las nuevas</b> cuando el atacante cambie de IP.</p>
                            <div class="f-row">
                                <div class="f" style="flex:0 1 170px;"><label for="net-kind">Tipo</label>
                                    <select class="form-control" id="net-kind" name="kind">
                                        <option value="asn" {{ old('kind', 'asn') === 'asn' ? 'selected' : '' }}>Operador (ASN)</option>
                                        <option value="country" {{ old('kind') === 'country' ? 'selected' : '' }}>País</option>
                                    </select></div>
                                <div class="f"><label for="net-value">Operador o país</label>
                                    <input class="form-control" id="net-value" name="value" value="{{ old('value') }}" placeholder="AS199585" required maxlength="120">
                                    <small id="net-hint">Número del operador: <code>AS199585</code> o <code>199585</code> (puedes pegar «AS199585 · Operador / residencial»).</small></div>
                            </div>
                            <div class="f-row">
                                <div class="f"><label for="net-hours">Duración</label>
                                    <select class="form-control" id="net-hours" name="hours">
                                        <option value="">Permanente</option>
                                        <option value="1">1 hora</option>
                                        <option value="24">24 horas</option>
                                        <option value="168">7 días</option>
                                        <option value="720">30 días</option>
                                    </select></div>
                                <div class="f"><label for="net-reason">Motivo</label><input class="form-control" id="net-reason" name="reason" maxlength="190" placeholder="Ataque DDoS"></div>
                            </div>
                            <label class="check"><input type="checkbox" name="force" value="1"><span>Bloquear aunque sea muy grande<small>Por seguridad se pide confirmar las redes de más de 4 millones de IPs (operadores grandes o países enteros).</small></span></label>
                            <div class="alert w" style="margin:10px 0 0;">Se bloquea a <b>todo</b> el que entre desde esa red, incluidos jugadores legítimos que usen ese operador. Tu IP, la de tus nodos, el panel, Cloudflare y la lista blanca nunca se bloquean (si la red las incluye, se rechaza).</div>
                        </div>
                        <div class="card-f"><button class="btn btn-danger" type="submit">Bloquear red</button></div>
                    </form>
                </div>
                <div class="col-2">
                    <div class="card">
                        <div class="card-h"><h3>Operadores y países bloqueados</h3>
                            @if ($netBlocks->isNotEmpty())
                                <form action="{{ route('admin.extensions.antiddos.networks.refresh') }}" method="POST" style="display:inline;">@csrf<button class="btn btn-sm btn-default" type="submit" title="Vuelve a descargar los rangos (se hace solo cada día)">Actualizar rangos</button></form>
                            @endif
                        </div>
                        <div class="tw">
                            <table class="table">
                                <thead><tr><th>Red</th><th>Rangos</th><th>IPs</th><th>Caduca</th><th>Motivo</th><th></th></tr></thead>
                                <tbody>
                                @forelse ($netBlocks as $net)
                                    <tr>
                                        <td><b>{{ $net->kind === 'asn' ? 'AS' . $net->value : 'País ' . $net->value }}</b>
                                            <div class="muted small">{{ $net->kind === 'asn' ? ($net->name ?: 'Operador') : 'País entero' }} · rangos de {{ $net->source ?: '—' }}{{ $net->refreshed_at ? ', ' . $net->refreshed_at->diffForHumans() : '' }}</div>
                                            <details style="margin:4px 0 0; padding:4px 8px; border:0;"><summary class="small muted" style="font-weight:normal;">ver rangos</summary><code class="small" style="display:block; white-space:normal; word-break:break-all;">{{ \Illuminate\Support\Str::limit(implode(', ', (array) $net->prefixes), 3000) }}</code></details></td>
                                        <td>{{ number_format($net->prefix_count, 0, ',', '.') }}</td>
                                        <td>{{ number_format($net->ip_count, 0, ',', '.') }}</td>
                                        <td>{{ $net->expires_at ? $net->expires_at->diffForHumans() : 'permanente' }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit((string) $net->reason, 60) }}</td>
                                        <td>
                                            <form action="{{ route('admin.extensions.antiddos.networks.destroy') }}" method="POST" style="display:inline;" onsubmit="return confirm('¿Desbloquear esta red?');">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="kind" value="{{ $net->kind }}">
                                                <input type="hidden" name="value" value="{{ $net->value }}">
                                                <button class="btn btn-xs btn-default" type="submit">Desbloquear</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="muted">No hay operadores ni países bloqueados.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-f small muted">
                            Se aplica en el <b>firewall de todos los nodos</b> (todo el tráfico de esa red, a cualquier puerto) y en el <b>panel</b> (responde 403). Los rangos se actualizan solos cada día por si el operador anuncia rangos nuevos.
                            @if ($cfExpression)
                                <div style="margin-top:10px;"><b>Si el panel está detrás de Cloudflare</b>, bloquéalo también allí para que esas peticiones ni lleguen a tu servidor: Cloudflare → tu dominio → <i>Security → WAF → Custom rules</i> → <i>Create rule</i> → <i>Edit expression</i>, pega esto y elige la acción <b>Block</b>:
                                    <pre>{{ $cfExpression }}<button type="button" class="btn btn-xs btn-default copy">Copiar</button></pre></div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pane" id="pane-safe">
            <div class="grid">
                <div class="col">
                    @if (count($serverIps) > 0 || !in_array($myIp, array_column($safeManual, 'entry'), true))
                        <div class="card" style="margin-bottom:16px;">
                            <div class="card-h"><h3>Atajos</h3></div>
                            <div class="card-b">
                                @if (count($serverIps) > 0)
                                    <form action="{{ route('admin.extensions.antiddos.protected.add') }}" method="POST" style="margin:0 0 12px;">
                                        @csrf
                                        <input type="hidden" name="entries" value="{{ implode("\n", $serverIps) }}">
                                        <input type="hidden" name="label" value="Este servidor">
                                        <p class="muted" style="margin:0 0 8px;">IPs de <b>este servidor</b> detectadas: <code>{{ implode(', ', $serverIps) }}</code></p>
                                        <button class="btn btn-sm btn-primary" type="submit">Proteger las IPs de este servidor</button>
                                    </form>
                                @endif
                                @if (!in_array($myIp, array_column($safeManual, 'entry'), true))
                                    <form action="{{ route('admin.extensions.antiddos.protected.add') }}" method="POST" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="entries" value="{{ $myIp }}">
                                        <input type="hidden" name="label" value="Mi IP">
                                        <p class="muted" style="margin:0 0 8px;">Tu IP actual: <code>{{ $myIp }}</code></p>
                                        <button class="btn btn-sm btn-default" type="submit">Proteger mi IP actual</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
                    <form action="{{ route('admin.extensions.antiddos.protected.add') }}" method="POST" class="card">
                        @csrf
                        <div class="card-h"><h3>Proteger IPs</h3></div>
                        <div class="card-b">
                            <p class="muted" style="margin-top:0;">Una IP protegida <b>nunca se bloquea</b>: ni el escudo del panel, ni el bloqueo automático, ni los operadores o países, ni el firewall de los nodos (se salta todos los límites y bloqueos). Úsalo para <b>tu propio servidor dedicado</b>, tus proxies, tu oficina o un servicio de confianza.</p>
                            <div class="f"><label for="safe-entries">IPs o rangos (uno por línea)</label>
                                <textarea class="form-control" id="safe-entries" name="entries" rows="5" placeholder="203.0.113.7&#10;198.51.100.0/24" required>{{ old('entries') }}</textarea>
                                <small>Admite rangos de hasta /16 (IPv4).</small></div>
                            <div class="f"><label for="safe-label">Nota (opcional)</label>
                                <input class="form-control" id="safe-label" name="label" maxlength="60" placeholder="Mi dedicado, proxy, oficina…" value="{{ old('label') }}"></div>
                        </div>
                        <div class="card-f"><button class="btn btn-primary" type="submit">Proteger</button></div>
                    </form>
                </div>
                <div class="col-2">
                    <div class="card">
                        <div class="card-h"><h3>IPs protegidas por ti</h3></div>
                        <div class="tw">
                            <table class="table">
                                <thead><tr><th>IP o rango</th><th>Nota</th><th></th></tr></thead>
                                <tbody>
                                @forelse ($safeManual as $row)
                                    <tr>
                                        <td><code>{{ $row['entry'] }}</code></td>
                                        <td>{{ $row['label'] !== '' ? $row['label'] : '—' }}</td>
                                        <td>
                                            <form action="{{ route('admin.extensions.antiddos.protected.remove') }}" method="POST" style="display:inline;" onsubmit="return confirm('¿Quitar esta IP de las protegidas? Volverá a poder bloquearse.');">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="entry" value="{{ $row['entry'] }}">
                                                <button class="btn btn-xs btn-default" type="submit">Quitar</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="muted">Todavía no has protegido ninguna IP a mano.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-f small muted">Se aplica a todos los nodos en menos de un minuto. Si una IP estaba bloqueada en el panel, se desbloquea al protegerla.</div>
                    </div>
                    <div class="card" style="margin-top:16px;">
                        <div class="card-h"><h3>Protegidas automáticamente</h3><span class="pill ok">{{ count($safeAuto) }}</span></div>
                        <div class="tw">
                            <table class="table">
                                <thead><tr><th>IP</th><th>Motivo</th></tr></thead>
                                <tbody>
                                @forelse ($safeAuto as $row)
                                    <tr><td><code>{{ $row['ip'] }}</code></td><td>{{ $row['why'] }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="muted">No se ha detectado ninguna todavía.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-f small muted">Son las IPs de este servidor, de tus nodos, de los agentes y del panel, más las redes privadas, Docker y Cloudflare. No se pueden quitar: bloquearlas dejaría el panel sin funcionar. Si falta alguna IP tuya (otra máquina, un proxy), añádela a mano.</div>
                    </div>
                </div>
            </div>
        </div>

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
                                        <td>{{ ['auto' => 'Escudo', 'manual' => 'Manual', 'intel' => 'Reputación'][$block->source] ?? $block->source }}</td>
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
                                <td><span class="pill {{ in_array($event->type, ['auto-ban', 'ban', 'net-ban', 'attack'], true) ? 'error' : '' }}">{{ $event->type }}</span></td>
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
            $$('[data-goto]').forEach(function (a) { a.addEventListener('click', function () { show(a.getAttribute('data-goto')); }); });
            show(location.hash.replace('#', '') || 'status');

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

            var netKind = $('#net-kind');
            if (netKind) {
                var netHint = function () {
                    var asn = netKind.value === 'asn';
                    $('#net-value').placeholder = asn ? 'AS199585' : 'PL';
                    $('#net-hint').innerHTML = asn
                        ? 'Número del operador: <code>AS199585</code> o <code>199585</code> (puedes pegar «AS199585 · Operador / residencial»).'
                        : 'Código de 2 letras del país: <code>PL</code>, <code>RU</code>, <code>CN</code>… Bloquea el país entero.';
                };
                netKind.addEventListener('change', netHint); netHint();
                $('#ad-netform').addEventListener('submit', function (e) {
                    var v = $('#net-value').value.trim();
                    if (!confirm('¿Bloquear ' + (netKind.value === 'asn' ? 'el operador ' : 'el país ') + v + ' entero en el panel y en todos los nodos? También se bloqueará a los jugadores legítimos de esa red.')) { e.preventDefault(); }
                });
            }

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

            var cv = $('#ad-chart');
            if (cv) {
                var URL_TRAFFIC = @json(route('admin.extensions.antiddos.traffic'));
                var URL_BLOCK = @json(route('admin.extensions.antiddos.blocks.store'));
                var CSRF = @json(csrf_token());
                var URL_WL = @json(route('admin.extensions.antiddos.whitelist.add'));
                var URL_RECHECK = @json(route('admin.extensions.antiddos.intel.recheck'));
                var URL_NET = @json(route('admin.extensions.antiddos.networks.store'));

                var PS = { ps: ['por segundo', 1], pm: ['por minuto', 60] };
                var VIEWS = {
                    network: {
                        units: { mbps: ['Mbit/s', 1], mbs: ['MB/s', 0.125], kbs: ['KB/s', 125] }, unit: 'mbps', stacked: false,
                        series: [['rx', '↓ Bajada (RX)', '#3c8dbc'], ['tx', '↑ Subida (TX)', '#dd4b39'], ['dropped', 'Frenado por el firewall', '#f39c12']],
                        note: 'Ancho de banda real de la tarjeta de red (todo el tráfico de la máquina, como la gráfica de OVH): ↓ Bajada = lo que entra, ↑ Subida = lo que sale. «Frenado» es lo que descarta el firewall (ya contado dentro de la bajada).'
                    },
                    packets: {
                        units: PS, unit: 'ps', stacked: false,
                        series: [['rx', 'Paquetes recibidos (interfaz)', '#3c8dbc'], ['tcp', 'TCP a puertos protegidos', '#00a65a'], ['udp', 'UDP a puertos protegidos', '#9b59b6'], ['syn', 'Conexiones nuevas', '#1abc9c'], ['dropped', 'Frenados', '#dd4b39']],
                        note: '«Paquetes recibidos» son todos los que entran por la tarjeta de red. TCP y UDP cuentan solo el tráfico externo hacia tus puertos protegidos (sin la lista blanca). «Frenados» es todo lo que descarta el firewall (suma de los motivos).'
                    },
                    reasons: {
                        units: PS, unit: 'ps', stacked: true,
                        series: [['d_ban', 'IP u operador bloqueado', '#dd4b39'], ['d_mal', 'Malformados / fuera de conexión', '#e67e22'], ['d_icmp', 'Ping (ICMP)', '#f1c40f'], ['d_syn', 'Límite de conexiones nuevas / SSH', '#9b59b6'], ['d_udp', 'Límite de UDP / amplificación', '#3498db'], ['d_conn', 'Límite de conexiones simultáneas', '#1abc9c'], ['d_web', 'Límite web', '#95a5a6']],
                        note: 'Por qué el firewall frena paquetes, apilado: la altura total es todo lo frenado. Si domina «IP u operador bloqueado», los atacantes ya estaban bloqueados; si domina «Límite de conexiones nuevas», hay un SYN flood en curso; «fuera de conexión» son floods de ACK/RST sin conexión abierta y «amplificación» son respuestas falsas de DNS/NTP/memcached… dirigidas a tus puertos.'
                    }
                };
                var state = { view: 'network', range: 'live', host: '', data: null, smooth: 1, scale: 'lin', paused: false, vis: {}, units: {}, loadedAt: 0, tried: 0, loading: false, failing: false, open: {}, topSig: '', fwSig: '' };
                Object.keys(VIEWS).forEach(function (n) { state.units[n] = VIEWS[n].unit; state.vis[n] = {}; VIEWS[n].series.forEach(function (x) { state.vis[n][x[0]] = true; }); });

                function V() { return VIEWS[state.view]; }
                function mult() { return V().units[state.units[state.view]][1]; }
                function unitName() { return V().units[state.units[state.view]][0]; }
                function pad(n) { return (n < 10 ? '0' : '') + n; }
                function fmtNum(v) {
                    if (v === null || v === undefined) { return '—'; }
                    var a = Math.abs(v), L = 'es-ES';
                    if (a >= 1e6) { return (v / 1e6).toLocaleString(L, { maximumFractionDigits: 2 }) + ' M'; }
                    if (a >= 1e4) { return (v / 1e3).toLocaleString(L, { maximumFractionDigits: 1 }) + ' k'; }
                    if (a >= 100) { return Math.round(v).toLocaleString(L); }
                    if (a >= 10) { return v.toLocaleString(L, { maximumFractionDigits: 1 }); }
                    return v.toLocaleString(L, { maximumFractionDigits: 2 });
                }
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

                function values(name) {
                    var raw = state.data.series[name] || [], m = mult(), k = state.smooth, out = [], win = [];
                    raw.forEach(function (x) {
                        if (x === null) { out.push(null); win = []; return; }
                        win.push(x * m); if (win.length > k) { win.shift(); }
                        out.push(win.reduce(function (a, b) { return a + b; }, 0) / win.length);
                    });
                    return out;
                }

                function renderStats() {
                    var box = $('#ad-stats'), d = state.data, html = '';
                    V().series.forEach(function (sdef) {
                        var st = d.stats && d.stats[sdef[0]], m = mult();
                        var now = st && st.now !== null ? st.now * m : null;
                        var sub = st && st.avg !== null ? 'media ' + fmtNum(st.avg * m) + ' · pico ' + fmtNum(st.peak * m) + ' a las ' + label(st.peak_t, false) : 'sin datos';
                        html += '<div class="kpi"><div class="l"><i style="background:' + sdef[2] + '"></i>' + sdef[1] + '</div>'
                              + '<div class="n" style="color:' + sdef[2] + '">' + fmtNum(now) + ' <span style="font-size:12px;font-weight:400;opacity:.7">' + unitName() + '</span></div>'
                              + '<div class="sub">' + sub + '</div></div>';
                    });
                    box.innerHTML = html;
                }

                function renderLegend() {
                    var box = $('#ad-legend'); box.innerHTML = '';
                    V().series.forEach(function (sdef) {
                        var lab = document.createElement('label');
                        lab.innerHTML = '<input type="checkbox" ' + (state.vis[state.view][sdef[0]] ? 'checked' : '') + '><i style="background:' + sdef[2] + '"></i> ' + sdef[1];
                        lab.querySelector('input').addEventListener('change', function () { state.vis[state.view][sdef[0]] = this.checked; draw(); });
                        box.appendChild(lab);
                    });
                }

                function renderUnits() {
                    var sel = $('#ad-unit'); sel.innerHTML = '';
                    Object.keys(V().units).forEach(function (u) {
                        var o = document.createElement('option'); o.value = u; o.textContent = V().units[u][0];
                        if (u === state.units[state.view]) { o.selected = true; } sel.appendChild(o);
                    });
                    $('#ad-note').textContent = V().note;
                }

                var TYPES = { residential: 'Operador / residencial', hosting: 'Servidor o hosting (probable)', vpn: 'VPN o proxy (probable)', unknown: '—' };
                var WHY = { syn: 'Flood de conexiones nuevas', ssh: 'Fuerza bruta contra SSH', manual: 'Bloqueo manual' };
                var VCLASS = { malicious: 'error', suspicious: 'warn', clean: 'ok' };

                function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; }
                function post(url, fields) {
                    var f = document.createElement('form'); f.method = 'POST'; f.action = url; f.style.display = 'none';
                    Object.keys(fields).concat(['_token']).forEach(function (k) {
                        var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = k === '_token' ? CSRF : fields[k]; f.appendChild(i);
                    });
                    document.body.appendChild(f); f.submit();
                }
                function originCell(row) {
                    var it = row.intel || {}, td = el('td', 'origin');
                    if (it.status === 'pending') { td.appendChild(el('span', 'muted', 'Consultando…')); return td; }
                    if (it.status === 'disabled') { td.appendChild(el('span', 'muted', 'Consulta desactivada')); return td; }
                    if (it.status === 'internal') { td.appendChild(el('span', 'muted', 'Red interna')); return td; }
                    if (it.status === 'partial') { var sp = el('span', 'muted', 'Origen no disponible'); sp.title = 'Los servicios de consulta no respondieron; se reintenta solo cada pocos minutos.'; td.appendChild(sp); return td; }
                    var place = [it.city, it.country].filter(Boolean).join(', ');
                    td.appendChild(document.createTextNode((it.flag ? it.flag + ' ' : '') + (place || 'Origen desconocido')));
                    return td;
                }
                function verdictCell(row) {
                    var it = row.intel || {}, td = el('td');
                    var p = el('span', 'pill ' + (VCLASS[it.verdict] || ''), it.verdict_text || '—');
                    if (it.reasons && it.reasons.length) { p.title = it.reasons.join('\n'); }
                    td.appendChild(p);
                    if (it.abuse !== null && it.abuse !== undefined) { td.appendChild(el('div', 'muted small', 'AbuseIPDB ' + it.abuse + ' %')); }
                    return td;
                }
                function detailRow(row, cols) {
                    var it = row.intel || {}, tr = el('tr', 'det'), td = el('td'); td.colSpan = cols;
                    var ul = el('ul');
                    (it.reasons || ['Sin información adicional.']).forEach(function (r) { ul.appendChild(el('li', '', r)); });
                    td.appendChild(ul);
                    var facts = [];
                    if (it.org) { facts.push('Proveedor: ' + it.org + (it.asn ? ' (' + it.asn + ')' : '')); }
                    if (it.region) { facts.push('Región: ' + it.region); }
                    if (it.lists && it.lists.length) { facts.push('Listas: ' + it.lists.join(', ')); }
                    if (it.checked_at) { facts.push('Consultado: ' + new Date(it.checked_at * 1000).toLocaleString('es-ES')); }
                    if (facts.length) { td.appendChild(el('div', 'muted', facts.join(' · '))); }
                    if (it.status && it.status !== 'pending' && it.status !== 'disabled' && it.status !== 'internal') {
                        var rb = el('button', 'btn btn-xs btn-default', '↻ Reintentar consulta'); rb.type = 'button'; rb.style.marginTop = '6px';
                        rb.addEventListener('click', function () { rb.disabled = true; rb.textContent = 'Consultando…'; post(URL_RECHECK, { ip: row.ip }); });
                        td.appendChild(rb);
                    }
                    tr.appendChild(td); return tr;
                }
                function netCell(row) {
                    var it = row.intel || {}, td = el('td', 'net');
                    td.appendChild(document.createTextNode(it.org || '—'));
                    if (it.asn || it.type) { td.appendChild(el('small', '', [it.asn, TYPES[it.type] && it.type !== 'unknown' ? TYPES[it.type] : ''].filter(Boolean).join(' · '))); }
                    return netActions(row, td);
                }
                function netActions(row, td) {
                    var it = row.intel || {};
                    if (row.net_blocked) {
                        var p = el('span', 'pill error small', 'Operador bloqueado'); p.title = row.net_blocked; td.appendChild(p);
                    } else if (it.asn && !row.safe_reason) {
                        var b = el('button', 'btn btn-xs btn-danger', '🚫 Bloquear ' + it.asn); b.type = 'button'; b.style.marginTop = '4px'; b.title = 'Bloquea todas las IPs de ' + it.asn + ', también las que use en el futuro';
                        b.addEventListener('click', function () {
                            var who = it.asn + (it.org ? ' (' + it.org + ')' : '');
                            if (confirm('¿Bloquear el operador ' + who + ' ENTERO en el panel y en todos los nodos?\n\nSe cortan todas sus IPs, también las nuevas si el atacante cambia de IP, y también a los jugadores legítimos de ese operador.')) {
                                post(URL_NET, { kind: 'asn', value: it.asn, reason: 'Desde la gráfica de tráfico (IP ' + row.ip + ')' });
                            }
                        });
                        td.appendChild(el('br')); td.appendChild(b);
                    }
                    return td;
                }

                function toggle(ip) { state.open[ip] = !state.open[ip]; state.topSig = ''; state.fwSig = ''; renderTop(); renderFwBans(); }

                function renderTop() {
                    var tb = $('#ad-top'), top = (state.data && state.data.top) || [];
                    var sig = JSON.stringify(top) + JSON.stringify(state.open);
                    if (sig === state.topSig) { return; } state.topSig = sig;
                    tb.innerHTML = '';
                    if (!top.length) { tb.innerHTML = '<tr><td colspan="6" class="muted">Ninguna IP externa con conexiones hacia tus puertos protegidos en este momento.</td></tr>'; return; }
                    top.forEach(function (row) {
                        var it = row.intel || {}, tr = el('tr');
                        tr.appendChild(originCell(row));
                        var tdIp = el('td'); var code = el('code', '', row.ip); tdIp.appendChild(code);
                        tdIp.appendChild(document.createTextNode(' ')); var more = el('span', 'link small', state.open[row.ip] ? 'ocultar' : 'detalles'); more.addEventListener('click', function () { toggle(row.ip); }); tdIp.appendChild(more);
                        tr.appendChild(tdIp);
                        tr.appendChild(netCell(row));
                        tr.appendChild(el('td', '', String(row.conns)));
                        tr.appendChild(verdictCell(row));
                        var act = el('td'); act.style.whiteSpace = 'nowrap';
                        if (row.blocked || row.net_blocked) { act.appendChild(el('span', 'pill error', 'Bloqueada')); }
                        else if (row.safe_reason) { var s1 = el('span', 'pill ok', 'Protegida'); s1.title = row.safe_reason; act.appendChild(s1); }
                        else {
                            var mal = it.verdict === 'malicious', minutes = mal ? 1440 : 60;
                            var b = el('button', 'btn btn-xs btn-' + (mal ? 'danger' : 'default'), mal ? 'Bloquear 24 h' : 'Bloquear 1 h'); b.type = 'button';
                            b.addEventListener('click', function () {
                                var msg = it.verdict === 'clean'
                                    ? 'Esta IP parece un jugador normal (sin listados y red de operador/residencial). ¿Seguro que quieres bloquear ' + row.ip + '?'
                                    : '¿Bloquear ' + row.ip + ' durante ' + (mal ? '24 horas' : '1 hora') + ' en el panel y en todos los nodos?';
                                if (confirm(msg)) { post(URL_BLOCK, { ip: row.ip, minutes: String(minutes), reason: 'Gráfica de tráfico · veredicto: ' + (it.verdict_text || 'sin datos') }); }
                            });
                            act.appendChild(b);
                        }
                        tr.appendChild(act); tb.appendChild(tr);
                        if (state.open[row.ip]) { tb.appendChild(detailRow(row, 6)); }
                    });
                }

                function renderFwBans() {
                    var tb = $('#ad-fwbans'), list = (state.data && state.data.fw_bans) || [];
                    var mode = state.data && state.data.ban_mode; $('#ad-banmode').textContent = { monitor: 'Observación (nunca bloquea)', balanced: 'Equilibrada', strict: 'Estricta' }[mode] || '—';
                    var sig = JSON.stringify(list.map(function (b) { return [b.ip, b.why, b.intel && b.intel.verdict, b.intel && b.intel.asn, b.safe_reason, b.net_blocked]; })) + JSON.stringify(state.open);
                    if (sig === state.fwSig) { return; } state.fwSig = sig;
                    tb.innerHTML = '';
                    if (!list.length) { tb.innerHTML = '<tr><td colspan="6" class="muted">El firewall no tiene ninguna IP bloqueada ahora mismo.</td></tr>'; return; }
                    list.forEach(function (b) {
                        var it = b.intel || {}, tr = el('tr'); tr.appendChild(originCell(b));
                        var tdIp = el('td'); tdIp.appendChild(el('code', '', b.ip)); tdIp.appendChild(document.createTextNode(' '));
                        var more = el('span', 'link small', state.open[b.ip] ? 'ocultar' : 'detalles'); more.addEventListener('click', function () { toggle(b.ip); }); tdIp.appendChild(more); tr.appendChild(tdIp);
                        var tdWhy = el('td', 'net', WHY[b.why] || b.why);
                        if (it.asn) { tdWhy.appendChild(el('small', '', it.asn + (it.org ? ' · ' + it.org : ''))); }
                        tr.appendChild(netActions(b, tdWhy));
                        tr.appendChild(el('td', '', b.left ? Math.floor(b.left / 60) + ' min' : 'sin caducidad'));
                        tr.appendChild(verdictCell(b));
                        var act = el('td'); act.style.whiteSpace = 'nowrap';
                        var w = el('button', 'btn btn-xs btn-' + (it.verdict === 'malicious' ? 'default' : 'success'), 'Es legítima'); w.type = 'button';
                        w.title = 'Añadir a la lista blanca y desbloquear';
                        w.addEventListener('click', function () {
                            if (confirm('¿Añadir ' + b.ip + ' a la lista blanca y desbloquearla? No se volverá a bloquear.')) { post(URL_WL, { ip: b.ip }); }
                        });
                        act.appendChild(w); tr.appendChild(act); tb.appendChild(tr);
                        if (state.open[b.ip]) { tb.appendChild(detailRow(b, 6)); }
                    });
                }

                function draw(hover) {
                    var d = state.data; if (!d) { return; }
                    var dpr = window.devicePixelRatio || 1;
                    var W = cv.clientWidth, H = cv.clientHeight;
                    if (!W) { return; }
                    cv.width = W * dpr; cv.height = H * dpr;
                    var g = cv.getContext('2d'); g.scale(dpr, dpr); g.clearRect(0, 0, W, H);
                    var fg = getComputedStyle(cv).color || '#888';
                    var L = 64, R = 14, T = 12, B = 28, w = W - L - R, h = H - T - B, n = d.labels.length;
                    var defs = V().series.filter(function (x) { return state.vis[state.view][x[0]]; });
                    var vals = {}; defs.forEach(function (x) { vals[x[0]] = values(x[0]); });
                    var stacked = V().stacked, log = state.scale === 'log';

                    function total(i) {
                        var t = 0, any = false;
                        defs.forEach(function (x) { var q = vals[x[0]][i]; if (q !== null) { t += q; any = true; } });
                        return any ? t : null;
                    }
                    var max = 0;
                    for (var i = 0; i < n; i++) {
                        if (stacked) { var tt = total(i); if (tt !== null) { max = Math.max(max, tt); } }
                        else { defs.forEach(function (x) { var q = vals[x[0]][i]; if (q !== null) { max = Math.max(max, q); } }); }
                    }
                    var top, ticks = [];
                    if (log) {
                        top = Math.pow(10, Math.max(1, Math.ceil(Math.log(Math.max(max, 1)) / Math.LN10)));
                        for (var p = 1; p <= top; p *= 10) { ticks.push(p); } ticks.unshift(0);
                    } else {
                        if (max <= 0) { max = 1; }
                        var mag = Math.pow(10, Math.floor(Math.log(max) / Math.LN10)), step = mag;
                        [1, 2, 2.5, 5, 10].some(function (m) { step = m * mag; return step >= max / 4; });
                        top = Math.max(step, Math.ceil(max / step) * step);
                        for (var v = 0; v <= top + step * 0.001; v += step) { ticks.push(v); }
                    }
                    var Y = function (val) { var f = log ? Math.log(1 + val) / Math.log(1 + top) : val / top; return T + h - Math.min(1, Math.max(0, f)) * h; };
                    var X = function (i) { return L + (n > 1 ? i / (n - 1) : 0) * w; };

                    g.font = '11px sans-serif'; g.fillStyle = fg; g.strokeStyle = 'rgba(127,127,127,.25)'; g.lineWidth = 1;
                    g.textAlign = 'right'; g.textBaseline = 'middle';
                    ticks.forEach(function (tk) { var y = Y(tk); g.beginPath(); g.moveTo(L, y); g.lineTo(W - R, y); g.stroke(); g.fillText(fmtNum(tk), L - 6, y); });
                    g.save(); g.translate(12, T + h / 2); g.rotate(-Math.PI / 2); g.textAlign = 'center'; g.fillText(unitName() + (log ? ' (log)' : ''), 0, 0); g.restore();
                    g.textAlign = 'center'; g.textBaseline = 'top';
                    var nt = Math.min(7, n);
                    for (var t = 0; t < nt; t++) { var idx = Math.round(t * (n - 1) / Math.max(1, nt - 1)); g.fillText(label(d.labels[idx]), X(idx), T + h + 7); }

                    function runs(arr) { var out = [], cur = []; arr.forEach(function (q, i) { if (q === null) { if (cur.length) { out.push(cur); cur = []; } } else { cur.push(i); } }); if (cur.length) { out.push(cur); } return out; }

                    if (stacked) {
                        var base = []; for (var z = 0; z < n; z++) { base.push(0); }
                        defs.forEach(function (x) {
                            var arr = vals[x[0]], lower = base.slice(), upper = [];
                            for (var i2 = 0; i2 < n; i2++) { upper.push(arr[i2] === null ? null : base[i2] + arr[i2]); }
                            runs(upper).forEach(function (run) {
                                g.beginPath();
                                run.forEach(function (i3, j) { j ? g.lineTo(X(i3), Y(upper[i3])) : g.moveTo(X(i3), Y(upper[i3])); });
                                for (var r = run.length - 1; r >= 0; r--) { g.lineTo(X(run[r]), Y(lower[run[r]])); }
                                g.closePath(); g.globalAlpha = 0.55; g.fillStyle = x[2]; g.fill(); g.globalAlpha = 1;
                                g.beginPath(); run.forEach(function (i4, j2) { j2 ? g.lineTo(X(i4), Y(upper[i4])) : g.moveTo(X(i4), Y(upper[i4])); });
                                g.strokeStyle = x[2]; g.lineWidth = 1.5; g.stroke();
                            });
                            for (var i5 = 0; i5 < n; i5++) { if (arr[i5] !== null) { base[i5] += arr[i5]; } }
                        });
                    } else {
                        defs.forEach(function (x) {
                            var arr = vals[x[0]];
                            runs(arr).forEach(function (run) {
                                g.beginPath();
                                run.forEach(function (i6, j) { j ? g.lineTo(X(i6), Y(arr[i6])) : g.moveTo(X(i6), Y(arr[i6])); });
                                g.strokeStyle = x[2]; g.lineWidth = 2; g.stroke();
                                if (run.length === 1) { g.fillStyle = x[2]; g.beginPath(); g.arc(X(run[0]), Y(arr[run[0]]), 2.5, 0, 6.3); g.fill(); }
                                else {
                                    g.lineTo(X(run[run.length - 1]), T + h); g.lineTo(X(run[0]), T + h); g.closePath();
                                    g.globalAlpha = 0.08; g.fillStyle = x[2]; g.fill(); g.globalAlpha = 1;
                                }
                            });
                        });
                    }

                    var tip = $('#ad-tip');
                    if (hover !== undefined && hover !== null && n > 0) {
                        var hx = X(hover);
                        g.strokeStyle = 'rgba(127,127,127,.6)'; g.beginPath(); g.moveTo(hx, T); g.lineTo(hx, T + h); g.stroke();
                        var html = '<b>' + label(d.labels[hover], true) + '</b>', any = false;
                        defs.forEach(function (x) {
                            var q = vals[x[0]][hover];
                            if (q !== null) { any = true; html += '<br><span style="color:' + x[2] + '">●</span> ' + x[1] + ': <b>' + fmtNum(q) + '</b> ' + unitName(); }
                        });
                        if (stacked && any) { html += '<br>Total: <b>' + fmtNum(total(hover)) + '</b> ' + unitName(); }
                        if (!any) { html += '<br>Sin datos del agente en este momento'; }
                        tip.innerHTML = html; tip.style.display = 'block';
                        tip.style.left = Math.min(W - 270, Math.max(0, hx + 12)) + 'px'; tip.style.top = (T + 6) + 'px';
                    } else { tip.style.display = 'none'; }
                }

                function messages() {
                    var d = state.data, empty = $('#ad-empty');
                    if (!d.has_data) {
                        empty.style.display = 'block';
                        empty.textContent = state.range === 'live'
                            ? 'Aún no llegan datos en vivo. Hace falta el servicio antiddos-live del nodo con la versión 1.6.0 o superior: actualiza con «sudo antiddos update --force». Mientras tanto puedes usar los rangos de 1 h o más.'
                            : (d.last_sample ? 'Todavía no hay suficientes datos en este periodo (hacen falta al menos dos muestras seguidas del agente). Espera un minuto.'
                                             : 'Aún no hay datos. Si el agente es anterior a la versión 1.6.0, actualízalo con: sudo antiddos update --force');
                    } else { empty.style.display = 'none'; }
                }

                function live() {
                    var el = $('#ad-live'), d = state.data;
                    var age = d && d.last_sample ? (d.generated_at - d.last_sample) + (Date.now() - state.loadedAt) / 1000 : 0, html;
                    if (state.paused) { html = '<span style="color:var(--warn)">⏸ En pausa</span> · pulsa «Reanudar» para seguir en directo'; }
                    else if (state.failing) { html = '<span style="color:var(--err)">● No se pudo actualizar</span> · reintentando…'; }
                    else if (!d) { html = '<span class="muted">Cargando…</span>'; }
                    else if (!d.last_sample) { html = '<span style="color:var(--warn)">● Sin datos todavía</span>'; }
                    else if (age > (state.range === 'live' ? 6 : 120)) { html = '<span style="color:var(--err)">● Sin datos recientes del agente</span> · último dato hace ' + ago(age) + ' (el agente no está informando)'; }
                    else { html = '<span style="color:var(--ok)">● En directo</span> · último dato del agente hace ' + ago(age) + ' · se actualiza ' + (state.range === 'live' ? 'cada segundo' : 'cada 15 s'); }
                    el.innerHTML = html;
                }

                function render() { renderStats(); renderLegend(); renderTop(); renderFwBans(); messages(); draw(); live(); }

                function load() {
                    if (state.loading) { return; }
                    state.loading = true; state.tried = Date.now();
                    var url = URL_TRAFFIC + '?range=' + encodeURIComponent(state.range) + '&view=' + encodeURIComponent(state.view) + (state.host ? '&host=' + encodeURIComponent(state.host) : '');
                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
                        .then(function (json) {
                            if (json.view !== state.view) { return; }
                            state.data = json; state.loadedAt = Date.now(); state.failing = false; render();
                        })
                        .catch(function () { state.failing = true; live(); })
                        .then(function () { state.loading = false; });
                }

                function reloadNow() { state.data = null; state.loading = false; $('#ad-stats').innerHTML = ''; load(); }

                $$('#ad-view button').forEach(function (b) {
                    b.addEventListener('click', function () {
                        $$('#ad-view button').forEach(function (x) { x.classList.toggle('on', x === b); });
                        state.view = b.getAttribute('data-v'); renderUnits(); renderLegend(); reloadNow();
                    });
                });
                $$('#ad-range button').forEach(function (b) {
                    b.addEventListener('click', function () {
                        $$('#ad-range button').forEach(function (x) { x.classList.toggle('on', x === b); });
                        state.range = b.getAttribute('data-r'); reloadNow();
                    });
                });
                $('#ad-host').addEventListener('change', function () { state.host = this.value; reloadNow(); });
                $('#ad-unit').addEventListener('change', function () { state.units[state.view] = this.value; if (state.data) { renderStats(); draw(); } });
                $('#ad-smooth').addEventListener('change', function () { state.smooth = parseInt(this.value, 10) || 1; draw(); });
                $('#ad-scale').addEventListener('change', function () { state.scale = this.value; draw(); });
                $('#ad-pause').addEventListener('click', function () {
                    state.paused = !state.paused; this.textContent = state.paused ? '▶ Reanudar' : '⏸ Pausar';
                    if (!state.paused) { load(); } live();
                });
                $('#ad-csv').addEventListener('click', function () {
                    var d = state.data; if (!d) { return; }
                    var m = mult(), cols = V().series, rows = [['hora'].concat(cols.map(function (c) { return c[1] + ' (' + unitName() + ')'; })).join(';')];
                    d.labels.forEach(function (ts, i) {
                        var dt = new Date(ts * 1000), stamp = dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()) + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ':' + pad(dt.getSeconds());
                        rows.push([stamp].concat(cols.map(function (c) { var q = d.series[c[0]][i]; return q === null ? '' : String(q * m).replace('.', ','); })).join(';'));
                    });
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(new Blob(['﻿' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
                    a.download = 'trafico-' + state.view + '-' + state.range + '.csv'; document.body.appendChild(a); a.click(); a.remove();
                });
                cv.addEventListener('mousemove', function (e) {
                    if (!state.data) { return; }
                    var rect = cv.getBoundingClientRect(), n = state.data.labels.length;
                    var rel = (e.clientX - rect.left - 64) / Math.max(1, rect.width - 78);
                    draw(Math.max(0, Math.min(n - 1, Math.round(rel * (n - 1)))));
                });
                cv.addEventListener('mouseleave', function () { draw(); });
                window.addEventListener('resize', function () { draw(); });

                tabs.forEach(function (a) { a.addEventListener('click', function () { if (a.getAttribute('data-tab') === 'traffic') { setTimeout(function () { if (!state.paused) { load(); } draw(); }, 30); } }); });
                document.addEventListener('visibilitychange', function () { if (!document.hidden && !state.paused) { load(); } });
                setInterval(function () {
                    if (document.hidden || state.paused) { return; }
                    var watching = state.range === 'live' && $('#pane-traffic').classList.contains('active');
                    if (Date.now() - state.tried >= (watching ? 950 : 14800)) { load(); }
                }, 250);
                setInterval(live, 1000);
                renderUnits(); renderLegend(); load();
            }
        })();
    </script>
@endsection
