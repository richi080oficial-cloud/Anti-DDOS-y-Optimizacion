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
        .ad .profiles { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 8px; }
        .ad .profile { flex: 1 1 230px; border: 2px solid var(--line); border-radius: 6px; padding: 12px 14px; cursor: pointer; display: block; font-weight: normal; margin: 0; }
        .ad .profile.sel { border-color: var(--pri); box-shadow: 0 0 0 1px var(--pri); }
        .ad .profile b { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 15px; }
        .ad .chips span { display: inline-block; margin: 6px 6px 0 0; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: rgba(127,127,127,.18); }
        .ad .table { width: 100%; color: inherit; background: transparent; margin: 0; }
        .ad .table > thead > tr > th, .ad .table > tbody > tr > td { border-color: var(--line); vertical-align: middle; padding: 8px; }
        .ad .tw { overflow-x: auto; }
        .ad details { border: 1px solid var(--line); border-radius: 6px; padding: 10px 14px; margin: 12px 0; }
        .ad details > summary { cursor: pointer; font-weight: 600; }
        .ad .alert { color: inherit; background: rgba(127,127,127,.12); border: 1px solid var(--line); border-left-width: 5px; border-radius: 4px; padding: 11px 14px; margin-bottom: 14px; }
        .ad .alert.s { border-left-color: var(--ok); } .ad .alert.e { border-left-color: var(--err); } .ad .alert.w { border-left-color: var(--warn); }
        .ad .atk { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .ad .atk .state { font-weight: 700; font-size: 15px; }
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
            <li><a data-tab="shield">Protección</a></li>
            <li><a data-tab="blocks">Bloqueos ({{ $blocks->count() }})</a></li>
            <li><a data-tab="optimize">Optimización</a></li>
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
                            <div class="f-row">
                                <div class="f">
                                    <label for="tcp">Puertos TCP de Minecraft</label>
                                    <input class="form-control" id="tcp" name="tcp" value="{{ old('tcp', $fw['tcp']) }}" placeholder="25565:25666" required>
                                    <small>Un puerto (<code>25565</code>), un rango (<code>25565:25666</code>) o varios separados por comas.</small>
                                </div>
                                <div class="f">
                                    <label for="udp">Puertos UDP (Bedrock/Geyser)</label>
                                    <input class="form-control" id="udp" name="udp" value="{{ old('udp', $fw['udp']) }}" placeholder="19132">
                                    <small>Déjalo vacío si no usas Bedrock.</small>
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
                            <div class="f"><label for="heap_percent">% de RAM para Java (optimización)</label>
                                <input class="form-control" type="number" id="heap_percent" name="heap_percent" min="60" max="95" value="{{ old('heap_percent', $settings['heap_percent']) }}">
                                <small>El resto queda para memoria nativa; al 100 % Docker mata el servidor.</small></div>
                            <div class="f"><label for="default_profile">Perfil preseleccionado</label>
                                <select class="form-control" id="default_profile" name="default_profile">
                                    @foreach ($profiles as $key => $profile)
                                        <option value="{{ $key }}" {{ old('default_profile', $settings['default_profile']) === $key ? 'selected' : '' }}>{{ $profile['label'] }}</option>
                                    @endforeach
                                </select></div>
                        </div>
                        <label class="check"><input type="checkbox" name="log_events" value="1" {{ $settings['log_events'] ? 'checked' : '' }}><span>Registrar eventos</span></label>
                    </details>
                </div>
                <div class="card-f"><button class="btn btn-primary" type="submit">Guardar escudo web</button></div>
            </form>
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

        {{-- ═══════════════════════ OPTIMIZACIÓN ═══════════════════════ --}}
        <div class="pane" id="pane-optimize">
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

            <form action="{{ route('admin.extensions.antiddos.optimize') }}" method="POST" id="ad-opt">
                @csrf

                <div class="card">
                    <div class="card-h"><h3>1 · Elige un perfil</h3></div>
                    <div class="card-b">
                        <div class="profiles">
                            @foreach ($profiles as $key => $profile)
                                <label class="profile {{ $settings['default_profile'] === $key ? 'sel' : '' }}">
                                    <b><input type="radio" name="profile" value="{{ $key }}" {{ $settings['default_profile'] === $key ? 'checked' : '' }}> {{ $profile['label'] }}</b>
                                    <span class="muted">{{ $profile['description'] }}</span>
                                    <div class="chips">
                                        <span>visión {{ $profile['properties']['view-distance'] }}</span>
                                        <span>simulación {{ $profile['properties']['simulation-distance'] }}</span>
                                        <span>monstruos {{ $profile['bukkit']['spawn-limits.monsters'] ?? '—' }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        <details>
                            <summary>Comparar los perfiles</summary>
                            <div class="tw" style="margin-top:10px;"><table class="table">
                                <thead><tr><th>Ajuste</th>@foreach ($profiles as $profile)<th>{{ $profile['label'] }}</th>@endforeach</tr></thead>
                                <tbody>
                                @foreach ([
                                    'Distancia de visión' => ['properties', 'view-distance'],
                                    'Distancia de simulación' => ['properties', 'simulation-distance'],
                                    'Rango de envío de entidades (%)' => ['properties', 'entity-broadcast-range-percentage'],
                                    'Límite de monstruos' => ['bukkit', 'spawn-limits.monsters'],
                                    'Límite de animales' => ['bukkit', 'spawn-limits.animals'],
                                    'Rango de aparición de mobs' => ['spigot', 'world-settings.default.mob-spawn-range'],
                                    'Actividad de monstruos (bloques)' => ['spigot', 'world-settings.default.entity-activation-range.monsters'],
                                    'Colisiones máx. por entidad' => ['paper_world', 'collisions.max-entity-collisions'],
                                ] as $label => [$section, $key])
                                    <tr><td>{{ $label }}</td>@foreach ($profiles as $profile)<td>{{ $profile[$section][$key] ?? '—' }}</td>@endforeach</tr>
                                @endforeach
                                </tbody></table></div>
                            <p class="muted small" style="margin:8px 0 0;">«—» = ese perfil no toca ese ajuste. Solo se cambian claves que ya existen en los archivos de cada servidor.</p>
                        </details>
                    </div>
                </div>

                <div class="card">
                    <div class="card-h"><h3>2 · Qué aplicar</h3></div>
                    <div class="card-b">
                        <label class="check"><input type="checkbox" name="flags" value="1" checked>
                            <span>Mejorar el arranque de Java <small>Banderas de la JVM (Aikar) y memoria proporcional a la RAM del servidor. Reduce los tirones del recolector de basura.</small></span></label>
                        <label class="check"><input type="checkbox" name="files" value="1" checked>
                            <span>Optimizar los archivos del servidor <small>server.properties, spigot.yml, bukkit.yml y paper-world-defaults.yml. Se guarda una copia para poder revertir.</small></span></label>
                        <label class="check"><input type="checkbox" name="restart" value="1">
                            <span>Reiniciar los servidores encendidos <small>Para que surta efecto ya. Si no, se aplica en el próximo reinicio.</small></span></label>

                        <details>
                            <summary>Personalizar (opcional)</summary>
                            <p class="muted small" style="margin:10px 0;">Lo que rellenes aquí tiene prioridad sobre el perfil. Déjalo vacío para usar los valores del perfil.</p>
                            <div class="f-row">
                                <div class="f"><label for="view_distance">Distancia de visión</label>
                                    <input class="form-control" type="number" id="view_distance" name="view_distance" min="2" max="32" placeholder="Según perfil"><small>Chunks que ve cada jugador. La opción que más CPU ahorra.</small></div>
                                <div class="f"><label for="simulation_distance">Distancia de simulación</label>
                                    <input class="form-control" type="number" id="simulation_distance" name="simulation_distance" min="2" max="32" placeholder="Según perfil"><small>Chunks donde se simulan mobs y cultivos.</small></div>
                                <div class="f"><label for="entity_broadcast">Rango de entidades (%)</label>
                                    <input class="form-control" type="number" id="entity_broadcast" name="entity_broadcast" min="10" max="100" placeholder="Según perfil"><small>Menos % = menos tráfico por jugador.</small></div>
                                <div class="f"><label for="collisions">Colisiones máx. por entidad</label>
                                    <input class="form-control" type="number" id="collisions" name="collisions" min="0" max="8" placeholder="Según perfil"><small>Paper. 2 reduce mucho la carga en granjas.</small></div>
                            </div>
                            <div class="f-row">
                                <div class="f"><label for="mobs">Cantidad de mobs</label>
                                    <select class="form-control" id="mobs" name="mobs">
                                        <option value="profile">Según perfil</option>
                                        <option value="reduced">Reducida</option>
                                        <option value="minimum">Mínima</option>
                                    </select><small>Límites de aparición y rango de spawn.</small></div>
                                <div class="f"><label for="heap_percent_opt">% de RAM para Java</label>
                                    <input class="form-control" type="number" id="heap_percent_opt" name="heap_percent" min="60" max="95" placeholder="{{ $settings['heap_percent'] }}"><small>Por defecto el de la pestaña Protección.</small></div>
                            </div>
                            <label class="check"><input type="checkbox" name="redstone" value="1"><span>Redstone alternativa (Alternate Current) <small>Mucho más rápida; puede alterar circuitos muy técnicos.</small></span></label>
                            <label class="check"><input type="checkbox" name="no_treasure_maps" value="1"><span>Desactivar mapas del tesoro <small>Evita generar chunks lejanos al explorar.</small></span></label>
                        </details>
                    </div>
                </div>

                <div class="card">
                    <div class="card-h"><h3>3 · Elige los servidores</h3><span class="muted small" id="ad-count">0 seleccionados</span></div>
                    <div class="card-b">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; align-items:center;">
                            <input type="text" id="ad-filter" class="form-control" style="max-width:240px;" placeholder="Buscar servidor…">
                            <select id="ad-node" class="form-control" style="max-width:200px;">
                                <option value="">Todos los nodos</option>
                                @foreach ($servers->pluck('node.name')->filter()->unique()->sort() as $nodeName)
                                    <option value="{{ \Illuminate\Support\Str::lower($nodeName) }}">{{ $nodeName }}</option>
                                @endforeach
                            </select>
                            <label class="check" style="margin:0;"><input type="checkbox" id="ad-only-new"><span>Solo sin optimizar</span></label>
                            <button type="button" class="btn btn-default" id="ad-all">Marcar visibles</button>
                            <button type="button" class="btn btn-default" id="ad-none">Quitar marcas</button>
                        </div>
                        <div class="tw">
                            <table class="table" id="ad-servers">
                                <thead><tr><th style="width:30px;"></th><th>Servidor</th><th>Tipo</th><th>Nodo</th><th>RAM</th><th>Optimización</th></tr></thead>
                                <tbody>
                                @forelse ($servers as $server)
                                    @php($state = $states[$server->id] ?? null)
                                    <tr data-name="{{ \Illuminate\Support\Str::lower($server->name . ' ' . ($server->egg->name ?? '')) }}" data-node="{{ \Illuminate\Support\Str::lower($server->node->name ?? '') }}" data-opt="{{ $state ? 1 : 0 }}">
                                        <td><input type="checkbox" name="servers[]" value="{{ $server->id }}" class="ad-srv"></td>
                                        <td><a href="{{ route('admin.servers.view', $server->id) }}">{{ $server->name }}</a></td>
                                        <td>{{ $server->egg->name ?? '—' }}</td>
                                        <td>{{ $server->node->name ?? '—' }}</td>
                                        <td>{{ $server->memory > 0 ? $server->memory . ' MiB' : 'ilimitada' }}</td>
                                        <td>@if ($state)<span class="pill ok">{{ $profiles[$state->profile]['label'] ?? $state->profile }}</span> <span class="muted small">{{ $state->updated_at->diffForHumans() }}</span>@else<span class="muted">Sin optimizar</span>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="muted">No se encontraron servidores de Minecraft.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <p class="muted small" style="margin:10px 0 0;">Hasta {{ $batchLimit }} servidores por envío. Para todos a la vez: <code>sudo antiddos optimize-server apply --profile=balanced --all</code></p>
                    </div>
                    <div class="card-f">
                        <button type="submit" class="btn btn-success">Aplicar a los seleccionados</button>
                        <button type="submit" class="btn btn-default" formaction="{{ route('admin.extensions.antiddos.optimize.revert') }}"
                                onclick="return confirm('¿Revertir la optimización de los servidores marcados? Se restaurarán el comando de arranque y los archivos originales.');">Revertir los seleccionados</button>
                    </div>
                </div>
            </form>
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
                                <td><span class="pill {{ in_array($event->type, ['auto-ban', 'ban', 'attack'], true) ? 'error' : (in_array($event->type, ['optimize', 'optimize-revert'], true) ? 'ok' : '') }}">{{ $event->type }}</span></td>
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

            // Perfiles
            $$('.profile input').forEach(function (r) {
                r.addEventListener('change', function () {
                    $$('.profile').forEach(function (p) { p.classList.toggle('sel', $('input', p).checked); });
                });
            });

            // Lista de servidores: filtros y seleccion
            var rows = $$('#ad-servers tbody tr[data-name]');
            var count = $('#ad-count');
            function refresh() { if (count) { count.textContent = $$('.ad-srv:checked').length + ' seleccionados'; } }
            function filter() {
                var q = ($('#ad-filter').value || '').toLowerCase().trim();
                var node = $('#ad-node').value;
                var onlyNew = $('#ad-only-new').checked;
                rows.forEach(function (tr) {
                    var ok = (!q || tr.getAttribute('data-name').indexOf(q) !== -1)
                          && (!node || tr.getAttribute('data-node') === node)
                          && (!onlyNew || tr.getAttribute('data-opt') === '0');
                    tr.style.display = ok ? '' : 'none';
                });
            }
            if ($('#ad-filter')) {
                ['input', 'change'].forEach(function (ev) {
                    $('#ad-filter').addEventListener(ev, filter);
                    $('#ad-node').addEventListener(ev, filter);
                    $('#ad-only-new').addEventListener(ev, filter);
                });
                $('#ad-all').addEventListener('click', function () {
                    rows.forEach(function (tr) { if (tr.style.display !== 'none') { $('.ad-srv', tr).checked = true; } }); refresh();
                });
                $('#ad-none').addEventListener('click', function () { $$('.ad-srv').forEach(function (c) { c.checked = false; }); refresh(); });
                $$('.ad-srv').forEach(function (c) { c.addEventListener('change', refresh); });
            }
        })();
    </script>
@endsection
