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

        })();
    </script>
@endsection
