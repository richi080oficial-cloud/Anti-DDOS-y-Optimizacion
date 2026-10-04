@extends('layouts.admin')

@section('title')
    Anti-DDoS y Optimización
@endsection

@section('content-header')
    <h1>Anti-DDoS y Optimización <small>anti-DDoS y optimización · v{{ $version }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Anti-DDoS y Optimización</li>
    </ol>
@endsection

@section('content')
    <style>
        /* Sin colores de fondo propios: heredan el tema activo (claro, oscuro o de terceros). */
        .vs-admin, .vs-admin * { box-sizing: border-box; }
        .vs-admin { width: 100%; max-width: 100%; clear: both; }
        .vs-admin .vs-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin: 0 0 15px; padding: 0; list-style: none; border-bottom: 1px solid rgba(127,127,127,.3); }
        .vs-admin .vs-tabs a { display: block; padding: 9px 14px; cursor: pointer; color: inherit; opacity: .75; border-bottom: 3px solid transparent; text-decoration: none; }
        .vs-admin .vs-tabs a:hover { opacity: 1; }
        .vs-admin .vs-tabs a.active { opacity: 1; font-weight: 600; border-bottom-color: #3c8dbc; }
        .vs-admin .vs-pane { display: none; }
        .vs-admin .vs-pane.active { display: block; }
        .vs-admin .box { background: transparent; box-shadow: none; border: 1px solid rgba(127,127,127,.28); border-top-width: 3px; border-radius: 4px; color: inherit; }
        .vs-admin .box-header, .vs-admin .box-body, .vs-admin .box-footer { background: transparent; color: inherit; }
        .vs-admin .box-header.with-border, .vs-admin .box-footer { border-color: rgba(127,127,127,.25); }
        .vs-admin .box.box-primary { border-top-color: #3c8dbc; }
        .vs-admin .box.box-success { border-top-color: #00a65a; }
        .vs-admin .box.box-warning { border-top-color: #f39c12; }
        .vs-admin .box.box-danger  { border-top-color: #dd4b39; }
        .vs-admin .text-muted { color: inherit; opacity: .7; }
        .vs-admin code, .vs-admin pre { background: rgba(127,127,127,.15); color: inherit; border: 0; border-radius: 3px; }
        .vs-admin pre { padding: 10px 12px; white-space: pre-wrap; word-break: break-all; position: relative; margin: 6px 0 12px; }
        .vs-admin .vs-copy { position: absolute; top: 6px; right: 6px; }
        .vs-admin .vs-grid { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .vs-admin .vs-col { flex: 1 1 280px; min-width: 0; }
        .vs-admin .vs-col-2 { flex: 2 1 420px; min-width: 0; }
        .vs-admin .vs-stat { text-align: center; padding: 12px 8px; }
        .vs-admin .vs-stat .n { font-size: 30px; font-weight: 600; line-height: 1.1; }
        .vs-admin .vs-field { margin-bottom: 12px; }
        .vs-admin .vs-field label { display: block; font-weight: 600; margin-bottom: 3px; }
        .vs-admin .vs-field small { display: block; opacity: .7; margin-top: 2px; }
        .vs-admin .vs-check { display: flex; align-items: center; gap: 8px; margin: 6px 0; font-weight: normal; cursor: pointer; }
        .vs-admin .vs-check input { margin: 0; }
        .vs-admin .vs-profiles { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
        .vs-admin .vs-profile { flex: 1 1 220px; border: 1px solid rgba(127,127,127,.35); border-radius: 4px; padding: 10px 12px; cursor: pointer; display: block; font-weight: normal; }
        .vs-admin .vs-profile input { margin-right: 6px; }
        .vs-admin .vs-profile.selected { border-color: #3c8dbc; box-shadow: 0 0 0 1px #3c8dbc; }
        .vs-admin .vs-profile b { display: block; margin-bottom: 3px; }
        .vs-admin .table { color: inherit; background: transparent; }
        .vs-admin .table > thead > tr > th, .vs-admin .table > tbody > tr > td { border-color: rgba(127,127,127,.2); vertical-align: middle; }
        .vs-admin .table-wrap { overflow-x: auto; }
        .vs-admin .vs-report td:nth-child(2) { width: 60px; }
        .vs-admin .alert { color: inherit; background: rgba(127,127,127,.12); border: 1px solid rgba(127,127,127,.35); border-left-width: 4px; }
        .vs-admin .alert-success { border-left-color: #00a65a; }
        .vs-admin .alert-danger { border-left-color: #dd4b39; }
        .vs-admin .alert-warning { border-left-color: #f39c12; }
    </style>

    <div class="vs-admin">
        @if (session('antiddos:success'))
            <div class="alert alert-success">{{ session('antiddos:success') }}</div>
        @endif
        @if (session('antiddos:error'))
            <div class="alert alert-danger">{{ session('antiddos:error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul style="margin:0; padding-left:18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (session('antiddos:newkey'))
            <div class="alert alert-warning">
                <b>Nueva clave de la API (cópiala ahora, no se vuelve a mostrar):</b>
                <pre id="vs-newkey" style="margin-top:8px;">{{ session('antiddos:newkey') }}</pre>
            </div>
        @endif

        <ul class="vs-tabs" id="vs-tabs">
            <li><a data-tab="overview">Resumen</a></li>
            <li><a data-tab="shield">Escudo web</a></li>
            <li><a data-tab="blocks">Bloqueos ({{ $blocks->count() }})</a></li>
            <li><a data-tab="optimize">Optimización</a></li>
            <li><a data-tab="nodes">Nodos (anti-DDoS)</a></li>
            <li><a data-tab="events">Eventos</a></li>
        </ul>

        {{-- ───────────────────────── RESUMEN ───────────────────────── --}}
        <div class="vs-pane" id="pane-overview">
            <div class="vs-grid">
                <div class="vs-col box box-{{ $settings['enabled'] ? 'success' : 'danger' }}"><div class="box-body vs-stat">
                    <div class="n">{{ $settings['enabled'] ? 'ACTIVO' : 'APAGADO' }}</div>
                    <div class="text-muted">Escudo web del panel</div>
                </div></div>
                <div class="vs-col box box-primary"><div class="box-body vs-stat">
                    <div class="n">{{ $blocks->count() }}</div>
                    <div class="text-muted">IPs bloqueadas ahora</div>
                </div></div>
                <div class="vs-col box box-primary"><div class="box-body vs-stat">
                    <div class="n">{{ $states->count() }}</div>
                    <div class="text-muted">Servidores optimizados (de los listados)</div>
                </div></div>
                <div class="vs-col box box-{{ $hasApiKey ? 'success' : 'warning' }}"><div class="box-body vs-stat">
                    <div class="n">{{ $hasApiKey ? 'OK' : 'SIN CLAVE' }}</div>
                    <div class="text-muted">Clave de la API para los nodos</div>
                </div></div>
            </div>

            <div class="vs-grid">
                <div class="vs-col-2 box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">Cómo te protege Anti-DDoS y Optimización</h3></div>
                    <div class="box-body">
                        <p><b>1. Escudo web (este panel).</b> Limita las peticiones por IP al panel y bloquea a quien se pasa, antes de que la petición llegue a la base de datos. Protege la <i>web</i> contra ataques de capa 7 y fuerza bruta del login.</p>
                        <p><b>2. Agente de nodo (cada máquina con Wings).</b> Es lo que frena los ataques a los <i>servidores de Minecraft</i>: firewall con iptables/ipset que descarta SYN floods, UDP floods, paquetes malformados y limita las conexiones por IP <u>antes</u> de que consuman CPU. Se instala por consola en cada nodo (pestaña <a href="#nodes" data-goto="nodes">Nodos</a>) y recibe automáticamente los bloqueos de este panel.</p>
                        <p><b>3. Optimización.</b> Perfiles listos para Minecraft (flags de la JVM, distancias, mobs, redstone…) que se aplican a los servidores con un clic y se pueden revertir.</p>
                        <p class="text-muted" style="margin-bottom:0;">Ningún software puede frenar un ataque volumétrico que sature el enlace de tu proveedor: para eso necesitas protección anti-DDoS en la red del hosting o un proxy tipo TCPShield/Cloudflare Spectrum. Anti-DDoS y Optimización cubre todo lo que sí se puede parar en la propia máquina.</p>
                    </div>
                </div>
                <div class="vs-col box box-warning">
                    <div class="box-header with-border"><h3 class="box-title">Lista de comprobación</h3></div>
                    <div class="box-body">
                        <p>{{ $settings['enabled'] ? '✅' : '⚠️' }} Escudo web {{ $settings['enabled'] ? 'activo' : 'apagado' }}</p>
                        <p>{{ $hasApiKey ? '✅' : '⚠️' }} Clave de API {{ $hasApiKey ? 'creada' : 'sin crear (ejecuta: sudo antiddos apikey)' }}</p>
                        <p>⬜ Agente instalado en cada nodo: <code>sudo antiddos node-install</code></p>
                        <p>⬜ Panel en otra máquina: añade su IP a la lista blanca de cada nodo</p>
                        <p style="margin-bottom:0;">⬜ Perfil de optimización aplicado a los servidores</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────────────────── ESCUDO WEB ───────────────────────── --}}
        <div class="vs-pane" id="pane-shield">
            <form action="{{ route('admin.extensions.antiddos.settings.update') }}" method="POST">
                @csrf
                @method('PATCH')
                <div class="vs-grid">
                    <div class="vs-col box box-primary">
                        <div class="box-header with-border"><h3 class="box-title">Límites por IP</h3></div>
                        <div class="box-body">
                            <label class="vs-check"><input type="checkbox" name="enabled" value="1" {{ $settings['enabled'] ? 'checked' : '' }}> Escudo web activado</label>
                            <div class="vs-field">
                                <label for="general_limit">Peticiones por minuto y por IP</label>
                                <input class="form-control" type="number" id="general_limit" name="general_limit" min="30" max="100000" value="{{ old('general_limit', $settings['general_limit']) }}">
                                <small>No cuentan los assets estáticos ni la API de Wings. Un usuario normal hace muy pocas; sube el valor si hay oficinas/colegios con una sola IP pública.</small>
                            </div>
                            <div class="vs-field">
                                <label for="auth_limit">Intentos de login por minuto y por IP</label>
                                <input class="form-control" type="number" id="auth_limit" name="auth_limit" min="3" max="1000" value="{{ old('auth_limit', $settings['auth_limit']) }}">
                                <small>Frena la fuerza bruta contra el inicio de sesión.</small>
                            </div>
                        </div>
                    </div>
                    <div class="vs-col box box-primary">
                        <div class="box-header with-border"><h3 class="box-title">Bloqueo automático</h3></div>
                        <div class="box-body">
                            <div class="vs-field">
                                <label for="ban_minutes">Duración del primer bloqueo (minutos)</label>
                                <input class="form-control" type="number" id="ban_minutes" name="ban_minutes" min="1" max="10080" value="{{ old('ban_minutes', $settings['ban_minutes']) }}">
                            </div>
                            <div class="vs-field">
                                <label for="ban_max_minutes">Duración máxima (minutos)</label>
                                <input class="form-control" type="number" id="ban_max_minutes" name="ban_max_minutes" min="1" max="43200" value="{{ old('ban_max_minutes', $settings['ban_max_minutes']) }}">
                                <small>Cada reincidencia duplica el bloqueo hasta este máximo.</small>
                            </div>
                            <div class="vs-field">
                                <label for="retention_days">Días que se guardan los eventos</label>
                                <input class="form-control" type="number" id="retention_days" name="retention_days" min="1" max="365" value="{{ old('retention_days', $settings['retention_days']) }}">
                            </div>
                            <label class="vs-check"><input type="checkbox" name="log_events" value="1" {{ $settings['log_events'] ? 'checked' : '' }}> Registrar eventos</label>
                        </div>
                    </div>
                    <div class="vs-col box box-primary">
                        <div class="box-header with-border"><h3 class="box-title">Lista blanca</h3></div>
                        <div class="box-body">
                            <div class="vs-field">
                                <label for="whitelist">IPs o rangos que nunca se bloquean</label>
                                <textarea class="form-control" id="whitelist" name="whitelist" rows="6" placeholder="203.0.113.7&#10;198.51.100.0/24">{{ old('whitelist', $settings['whitelist']) }}</textarea>
                                <small>Una por línea. Se envía también a los nodos. Las IPs privadas y de loopback nunca se bloquean. Tu IP actual: <code>{{ $myIp }}</code></small>
                            </div>
                        </div>
                    </div>
                    <div class="vs-col box box-primary">
                        <div class="box-header with-border"><h3 class="box-title">Optimización</h3></div>
                        <div class="box-body">
                            <div class="vs-field">
                                <label for="default_profile">Perfil preseleccionado</label>
                                <select class="form-control" id="default_profile" name="default_profile">
                                    @foreach ($profiles as $key => $profile)
                                        <option value="{{ $key }}" {{ old('default_profile', $settings['default_profile']) === $key ? 'selected' : '' }}>{{ $profile['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="vs-field">
                                <label for="heap_percent">% de la RAM para el heap de Java</label>
                                <input class="form-control" type="number" id="heap_percent" name="heap_percent" min="60" max="95" value="{{ old('heap_percent', $settings['heap_percent']) }}">
                                <small>El resto queda para metaspace, hilos y buffers nativos. Con el 100 % Docker mata el contenedor por falta de memoria.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Guardar configuración</button>
            </form>
        </div>

        {{-- ───────────────────────── BLOQUEOS ───────────────────────── --}}
        <div class="vs-pane" id="pane-blocks">
            <div class="vs-grid">
                <div class="vs-col box box-danger">
                    <div class="box-header with-border"><h3 class="box-title">Bloquear una IP</h3></div>
                    <form action="{{ route('admin.extensions.antiddos.blocks.store') }}" method="POST">
                        @csrf
                        <div class="box-body">
                            <div class="vs-field">
                                <label for="ban-ip">IP</label>
                                <input class="form-control" id="ban-ip" name="ip" placeholder="203.0.113.7" required>
                            </div>
                            <div class="vs-field">
                                <label for="ban-minutes">Minutos (vacío = permanente)</label>
                                <input class="form-control" id="ban-minutes" type="number" name="minutes" min="1" placeholder="60">
                            </div>
                            <div class="vs-field">
                                <label for="ban-reason">Motivo</label>
                                <input class="form-control" id="ban-reason" name="reason" maxlength="200" placeholder="Opcional">
                            </div>
                            <small class="text-muted">El bloqueo llega a los nodos en menos de un minuto.</small>
                        </div>
                        <div class="box-footer"><button class="btn btn-danger" type="submit">Bloquear</button></div>
                    </form>
                </div>
                <div class="vs-col-2 box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">IPs bloqueadas</h3></div>
                    <div class="box-body table-wrap">
                        <table class="table table-hover">
                            <thead><tr><th>IP</th><th>Origen</th><th>Caduca</th><th>Reinc.</th><th>Motivo</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($blocks as $block)
                                <tr>
                                    <td><code>{{ $block->ip }}</code></td>
                                    <td>{{ $block->source === 'auto' ? 'escudo' : ($block->source === 'manual' ? 'manual' : $block->source) }}</td>
                                    <td>{{ $block->expires_at ? $block->expires_at->diffForHumans() : 'permanente' }}</td>
                                    <td>{{ $block->strikes }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit((string) $block->reason, 60) }}</td>
                                    <td>
                                        <form action="{{ route('admin.extensions.antiddos.blocks.destroy') }}" method="POST" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="ip" value="{{ $block->ip }}">
                                            <button class="btn btn-xs btn-default" type="submit">Desbloquear</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted">No hay IPs bloqueadas.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────────────────── OPTIMIZACIÓN ───────────────────────── --}}
        <div class="vs-pane" id="pane-optimize">
            @if (session('antiddos:report'))
                <div class="box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">Informe de la última operación</h3></div>
                    <div class="box-body table-wrap">
                        <table class="table vs-report">
                            <tbody>
                            @foreach (session('antiddos:report') as [$serverName, $level, $message])
                                <tr>
                                    <td>{{ $serverName }}</td>
                                    <td>{{ $level === 'ok' ? '✅' : ($level === 'warn' ? '⚠️' : '❌') }}</td>
                                    <td>{{ $message }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <form action="{{ route('admin.extensions.antiddos.optimize') }}" method="POST" id="vs-optimize-form">
                @csrf
                <div class="box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">1. Elige un perfil</h3></div>
                    <div class="box-body">
                        <div class="vs-profiles">
                            @foreach ($profiles as $key => $profile)
                                <label class="vs-profile {{ $settings['default_profile'] === $key ? 'selected' : '' }}">
                                    <b><input type="radio" name="profile" value="{{ $key }}" {{ $settings['default_profile'] === $key ? 'checked' : '' }}> {{ $profile['label'] }}</b>
                                    <span class="text-muted">{{ $profile['description'] }}</span>
                                    <div class="text-muted" style="margin-top:6px; font-size:12px;">
                                        view-distance {{ $profile['properties']['view-distance'] }} ·
                                        simulation-distance {{ $profile['properties']['simulation-distance'] }}
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        <label class="vs-check"><input type="checkbox" name="flags" value="1" checked> Aplicar banderas de la JVM (Aikar) y heap proporcional al comando de arranque</label>
                        <label class="vs-check"><input type="checkbox" name="files" value="1" checked> Optimizar server.properties, spigot.yml, bukkit.yml y paper-world-defaults.yml (con copia de seguridad)</label>
                        <label class="vs-check"><input type="checkbox" name="restart" value="1"> Reiniciar los servidores que estén encendidos para que surta efecto ya</label>
                        <p class="text-muted" style="margin:8px 0 0;">Solo se modifican claves que ya existen en cada archivo. Si un servidor aún no ha arrancado nunca, esos archivos no existen: vuelve a aplicar el perfil después del primer arranque. Procesa hasta {{ $batchLimit }} servidores por envío; para todos a la vez usa <code>sudo antiddos optimize-server apply --profile=… --all</code>.</p>
                    </div>
                </div>

                <div class="box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">2. Elige los servidores</h3></div>
                    <div class="box-body">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            <input type="text" id="vs-filter" class="form-control" style="max-width:280px;" placeholder="Filtrar esta lista…">
                            <button type="button" class="btn btn-default" id="vs-select-all">Marcar visibles</button>
                            <button type="button" class="btn btn-default" id="vs-select-none">Desmarcar todos</button>
                        </div>
                        <div class="table-wrap">
                            <table class="table table-hover" id="vs-servers">
                                <thead><tr><th style="width:30px;"></th><th>Servidor</th><th>Egg</th><th>Nodo</th><th>RAM</th><th>Estado</th></tr></thead>
                                <tbody>
                                @forelse ($servers as $server)
                                    @php($state = $states[$server->id] ?? null)
                                    <tr data-name="{{ \Illuminate\Support\Str::lower($server->name . ' ' . ($server->egg->name ?? '') . ' ' . ($server->node->name ?? '')) }}">
                                        <td><input type="checkbox" name="servers[]" value="{{ $server->id }}" class="vs-srv"></td>
                                        <td><a href="{{ route('admin.servers.view', $server->id) }}">{{ $server->name }}</a></td>
                                        <td>{{ $server->egg->name ?? '—' }}</td>
                                        <td>{{ $server->node->name ?? '—' }}</td>
                                        <td>{{ $server->memory > 0 ? $server->memory . ' MiB' : 'ilimitada' }}</td>
                                        <td>
                                            @if ($state)
                                                <span class="label label-success">{{ $profiles[$state->profile]['label'] ?? $state->profile }}</span>
                                            @else
                                                <span class="text-muted">sin optimizar</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-muted">No se encontraron servidores de Minecraft{{ $search !== '' ? ' con esa búsqueda' : '' }}.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted" style="margin:6px 0 0;">Se listan hasta 300 servidores cuyo egg/nest parece de Minecraft (Paper, Spigot, Purpur, Forge, Fabric, BungeeCord, Velocity…).</p>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-success">Aplicar perfil a los marcados</button>
                        <button type="submit" class="btn btn-default" formaction="{{ route('admin.extensions.antiddos.optimize.revert') }}"
                                onclick="return confirm('¿Revertir la optimización de los servidores marcados? Se restaurarán el comando de arranque y los archivos originales.');">Revertir los marcados</button>
                    </div>
                </div>
            </form>
        </div>

        {{-- ───────────────────────── NODOS ───────────────────────── --}}
        <div class="vs-pane" id="pane-nodes">
            @php($keyShown = session('antiddos:newkey') ?: 'TU_CLAVE')
            <div class="vs-grid">
                <div class="vs-col-2 box box-danger">
                    <div class="box-header with-border"><h3 class="box-title">Instalar el firewall anti-DDoS en un nodo</h3></div>
                    <div class="box-body">
                        <p>Ejecútalo <b>como root en cada máquina con Wings</b> (y en la del panel si recibe jugadores). Cambia los puertos por los de tus servidores de Minecraft (<code>25565</code>, rangos <code>25565:25600</code>, Bedrock en <code>--udp</code>):</p>
                        <pre>bash &lt;(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/AntiDDoS/main/install.sh) node-install --tcp=25565 --panel-url={{ $panelUrl ?: 'https://tu-panel' }} --key={{ $keyShown }}<button type="button" class="btn btn-xs btn-default vs-copy">Copiar</button></pre>
                        <p class="text-muted">Con <code>--panel-url</code> y <code>--key</code> el nodo descarga cada 30 s las IPs bloqueadas y la lista blanca de este panel. La clave se muestra al instalar la extensión; si la perdiste, genera una nueva aquí a la derecha.</p>
                        <p><b>Si el panel está en otra máquina</b>, añade su IP a la lista blanca del nodo para no cortar nunca a Wings:</p>
                        <pre>sudo antiddos fw white-add IP_DEL_PANEL<button type="button" class="btn btn-xs btn-default vs-copy">Copiar</button></pre>
                    </div>
                </div>
                <div class="vs-col box box-warning">
                    <div class="box-header with-border"><h3 class="box-title">Clave de la API</h3></div>
                    <form action="{{ route('admin.extensions.antiddos.apikey') }}" method="POST"
                          onsubmit="return confirm('Se generará una clave nueva y los nodos con la anterior dejarán de sincronizar hasta que la actualices. ¿Continuar?');">
                        @csrf
                        <div class="box-body">
                            <p>Estado: <b>{{ $hasApiKey ? 'configurada' : 'sin configurar' }}</b></p>
                            <p class="text-muted">En un nodo ya instalado puedes cambiarla con <code>sudo antiddos fw set API_KEY NUEVA</code> y <code>sudo antiddos fw apply</code>.</p>
                        </div>
                        <div class="box-footer"><button type="submit" class="btn btn-warning">{{ $hasApiKey ? 'Regenerar clave' : 'Generar clave' }}</button></div>
                    </form>
                </div>
            </div>

            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Comandos útiles en el nodo</h3></div>
                <div class="box-body table-wrap">
                    <table class="table">
                        <tbody>
                        <tr><td><code>sudo antiddos attack-on</code></td><td><b>Modo «bajo ataque»</b>: límites 3 veces más estrictos al instante. Vuelve con <code>attack-off</code>.</td></tr>
                        <tr><td><code>sudo antiddos fw status</code></td><td>Estado, límites y paquetes descartados.</td></tr>
                        <tr><td><code>sudo antiddos fw top</code></td><td>IPs con más conexiones a tus puertos (para localizar al atacante).</td></tr>
                        <tr><td><code>sudo antiddos ban 1.2.3.4</code></td><td>Bloquea una IP en el firewall local y en el panel.</td></tr>
                        <tr><td><code>sudo antiddos whitelist add 1.2.3.4</code></td><td>IP o rango que nunca se bloquea.</td></tr>
                        <tr><td><code>sudo antiddos fw set TCP_PORTS 25565:25600</code></td><td>Cambia puertos/límites (y luego <code>fw apply</code>). Claves: TCP_PORTS, UDP_PORTS, WEB_PORTS, CONN_LIMIT, SYN_RATE, UDP_RATE, BAN_SECONDS…</td></tr>
                        <tr><td><code>sudo antiddos optimize-host apply</code></td><td>Ajusta el sistema del nodo (swap, THP, BBR, límites de ficheros).</td></tr>
                        <tr><td><code>sudo antiddos nginx-on</code></td><td>(En el panel) límites de peticiones en nginx: corta ataques HTTP antes de que lleguen a PHP.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ───────────────────────── EVENTOS ───────────────────────── --}}
        <div class="vs-pane" id="pane-events">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Últimos eventos</h3>
                    <div class="box-tools pull-right">
                        <form action="{{ route('admin.extensions.antiddos.events.clear') }}" method="POST" style="display:inline;"
                              onsubmit="return confirm('¿Vaciar el registro de eventos?');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-xs btn-default" type="submit">Vaciar</button>
                        </form>
                    </div>
                </div>
                <div class="box-body table-wrap">
                    <table class="table table-hover">
                        <thead><tr><th>Fecha</th><th>Tipo</th><th>IP</th><th>Detalle</th></tr></thead>
                        <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td style="white-space:nowrap;">{{ $event->created_at->format('Y-m-d H:i:s') }}</td>
                                <td><span class="label label-{{ in_array($event->type, ['auto-ban', 'ban'], true) ? 'danger' : (in_array($event->type, ['optimize', 'optimize-revert'], true) ? 'success' : 'default') }}">{{ $event->type }}</span></td>
                                <td>{{ $event->ip ? $event->ip : '—' }}</td>
                                <td>{{ $event->message }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">Aún no hay eventos.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var tabs = document.querySelectorAll('#vs-tabs a[data-tab]');
            var names = Array.prototype.map.call(tabs, function (a) { return a.getAttribute('data-tab'); });

            function show(name) {
                if (names.indexOf(name) === -1) { name = 'overview'; }
                names.forEach(function (n) {
                    document.getElementById('pane-' + n).classList.toggle('active', n === name);
                });
                Array.prototype.forEach.call(tabs, function (a) {
                    a.classList.toggle('active', a.getAttribute('data-tab') === name);
                });
                try { history.replaceState(null, '', '#' + name); } catch (e) { /* sin historial */ }
            }

            Array.prototype.forEach.call(tabs, function (a) {
                a.addEventListener('click', function () { show(a.getAttribute('data-tab')); });
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-goto]'), function (a) {
                a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-goto')); });
            });
            show(location.hash.replace('#', '') || 'overview');

            // Perfiles: resalta el elegido.
            var profiles = document.querySelectorAll('.vs-profile');
            Array.prototype.forEach.call(document.querySelectorAll('.vs-profile input'), function (r) {
                r.addEventListener('change', function () {
                    Array.prototype.forEach.call(profiles, function (p) {
                        p.classList.toggle('selected', p.querySelector('input').checked);
                    });
                });
            });

            // Lista de servidores: filtro y seleccion masiva (solo filas visibles).
            var filter = document.getElementById('vs-filter');
            var rows = document.querySelectorAll('#vs-servers tbody tr[data-name]');
            if (filter) {
                filter.addEventListener('input', function () {
                    var q = filter.value.toLowerCase().trim();
                    Array.prototype.forEach.call(rows, function (tr) {
                        tr.style.display = !q || tr.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
                    });
                });
            }
            function setVisible(checked) {
                Array.prototype.forEach.call(rows, function (tr) {
                    if (tr.style.display !== 'none') { tr.querySelector('.vs-srv').checked = checked; }
                });
            }
            var all = document.getElementById('vs-select-all');
            var none = document.getElementById('vs-select-none');
            if (all) { all.addEventListener('click', function () { setVisible(true); }); }
            if (none) {
                none.addEventListener('click', function () {
                    Array.prototype.forEach.call(document.querySelectorAll('.vs-srv'), function (c) { c.checked = false; });
                });
            }

            // Botones "Copiar" de los bloques de comandos.
            Array.prototype.forEach.call(document.querySelectorAll('.vs-copy'), function (btn) {
                btn.addEventListener('click', function () {
                    var pre = btn.parentNode;
                    var text = pre.textContent.replace(btn.textContent, '').trim();
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(function () {
                            btn.textContent = '¡Copiado!';
                            setTimeout(function () { btn.textContent = 'Copiar'; }, 1500);
                        });
                    }
                });
            });
        })();
    </script>
@endsection
