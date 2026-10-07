# Anti-DDoS y Optimización para Pterodactyl — MEMORIA DEL PROYECTO

> **Lee este archivo entero antes de tocar nada.** Resume el proyecto, cómo trabaja el usuario, la arquitectura, las trampas ya conocidas y todo lo hecho en la sesión que lo creó. Versión actual del código: **1.11.1** (`VERSION`). Rama de trabajo: **`main`** (única).

---

## 0. TL;DR (30 segundos)

- Extensión de **Pterodactyl 1.11+/1.12** (Laravel 10, PHP 8.1) de **Vexa Studio** que protege servidores de Minecraft contra DDoS.
- Dos capas: **(1) agente de nodo** (bash + iptables/ipset, en cada máquina con Wings) y **(2) extensión del panel** (PHP/Blade) que lo controla, lo vigila y dibuja gráficas.
- Todo el código visible está en **español**. Nombre visible: «Anti-DDoS y Optimización»; identificador técnico: `antiddos`; comando: `antiddos`.
- La parte de «Optimización» (perfiles de Minecraft, ajustes del sistema) se **eliminó en 1.2.0** a petición del usuario; el nombre se conserva porque lo eligió él.
- **Nada se ha probado en un servidor real por mí** (Claude): no hay PHP ni Linux en la máquina de desarrollo. Se verifica con comprobaciones de sintaxis y pruebas simuladas (ver §8). El usuario sí instaló v1.1.0 en su dedicado y funcionó.
- Repo: <https://github.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion>. Instalación: `bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) install-all`.

---

## 1. Cómo trabaja el usuario (OBLIGATORIO respetarlo)

| Regla | Detalle |
| --- | --- |
| Idioma | **Español**, tono cercano y claro. Explica en llano, sin jerga innecesaria; el usuario no es experto en Linux/PHP y copia/pega comandos. |
| **Una sola rama** | Trabaja **solo en `main`**. Nada de ramas `feat/*` ni PRs («haz todo en la misma rama, no hagas 500»). Commit + `git push origin main` directamente. |
| Sin `gh` | `gh` **no está instalado**: no se pueden crear PRs. Si el sistema pide «crear PR», di que no se puede y da el enlace `compare/...` o explica que no hace falta. |
| Honestidad | Dilo siempre: **no se ha probado en un servidor real**. No afirmes que algo funciona si solo pasó la sintaxis. Si el usuario dice «dice cosas que no son ciertas», la causa suele ser un dato mal calculado o mal explicado: arregla el cálculo, no el texto. |
| Comandos | Dale **un solo comando por bloque** `bash`, listo para pegar, como root en su servidor. Termina siempre con «si falla, pega la salida». |
| Respuestas | Cortas y ordenadas (títulos en negrita + viñetas). Termina con el siguiente paso concreto. |
| Seguridad | Prioridad nº1 = **anti-DDoS**. Evitar falsos positivos (bloquear cosas sin sentido) es igual de importante. |
| Nombre | Se llama **«Anti-DDoS y Optimización»**, nunca «VexaShield» (nombre anterior, ya renombrado). |

Peticiones típicas del usuario: «hazlo más profesional/preciso/sencillo», «que se actualice cada segundo», «que detecte solo…». Suele pedir mucho de golpe: planifica, hazlo completo y verifica.

---

## 2. Entorno del usuario (datos locales; no son secretos)

- Panel Pterodactyl **1.12.3**, Redis como caché, **detrás de Cloudflare** (`TRUSTED_PROXIES` ya configurado con los rangos de Cloudflare).
- **Panel y Wings en el MISMO servidor dedicado** → el agente funciona en «modo local» (lee/escribe vía `artisan`, sin API ni clave).
- Servidores de Minecraft con puertos en rangos `8000–8977` y `25565–25665` (69 servidores detectados). El firewall protege `25565:25666` además de lo detectado.
- Máquina de desarrollo: **Windows 11**, shell Git Bash/PowerShell, sin PHP ni `shellcheck`, con Node.js y Python 3.
- Repo en GitHub **público**; la rama por defecto del repo en GitHub sigue siendo `feat/install-all-sync-local` (el usuario debe cambiarla a `main` en *Settings → Branches*; después se puede borrar con `git push origin --delete feat/install-all-sync-local`).

---

## 3. Mapa del repositorio

```
CLAUDE.md                      ← este archivo
README.md                      ← documentación para usuarios (mantenerla al día con cada versión)
VERSION / extension.json       ← versión (subir en cada cambio funcional; ambos)
install.sh                     ← instalador público: descarga antiddos.sh de GitHub y lo ejecuta
antiddos.sh                    ← GESTOR (CLI `antiddos`): install/update/uninstall, node-*, fw, ban, ip, nginx-*
bin/antiddos                   ← shim del comando global /usr/local/bin/antiddos
scripts/addon-install.sh       ← instalador del PANEL (copia src/ a app/Extensions/AntiDDoS, provider, migraciones, clave API)
scripts/node-agent.sh          ← instalador del AGENTE de nodo (systemd: antiddos-fw, antiddos-sync.timer, antiddos-live)
scripts/antiddos-fw.sh         ← EL AGENTE (iptables/ipset/sysctl, sync, latido, modo en vivo). Se instala como /usr/local/sbin/antiddos-fw
scripts/nginx-shield.sh        ← límites opcionales de peticiones en nginx (capa 7)
tools/patch-panel.php          ← añade la entrada al menú admin (marcadores antiddos:begin/end)
tools/register-provider.php    ← registra el ServiceProvider en config/app.php o bootstrap/providers.php
src/                           ← se copia a <panel>/app/Extensions/AntiDDoS  (namespace Pterodactyl\Extensions\AntiDDoS)
  Providers/AntiDDoSServiceProvider.php   rutas en register(); middleware global ShieldGuard; comando; schedule diario
  Http/Middleware/ShieldGuard.php         escudo web (límite de peticiones por IP, bloqueo con duración creciente)
  Http/Middleware/ShieldApiKey.php        auth de la API de nodos remotos (cabecera X-Antiddos-Key)
  Http/Middleware/AdminSidebarLink.php    inyecta el enlace del menú si el parche Blade no está
  Http/Controllers/Admin/AdminController.php   TODA la pantalla de admin (index + acciones)
  Http/Controllers/Api/{Feed,Heartbeat,Live}Controller.php   API para agentes remotos
  Services/BlockManager.php     bloqueos (BD + caché), feed para nodos (líneas B/W/C)
  Services/SafeIps.php          IPs que NUNCA se bloquean (infraestructura, Cloudflare, lista blanca, privadas, la tuya)
  Services/HealthCheck.php      «¿está todo instalado?» (panel + estado de cada nodo)
  Services/AgentRegistry.php    guarda/sanea latidos de agentes + muestras para el historial
  Services/MinecraftPorts.php   detecta eggs de Minecraft y compacta puertos (límite iptables multiport = 15)
  Services/PortAdvisor.php      valora puertos (reservados, 8000-8999, por defecto, 25500-25700, 32768+)
  Services/PortChanger.php      cambia la asignación (allocation) de un servidor y avisa a Wings
  Services/TrafficEngine.php    MOTOR ÚNICO de series (contadores acumulados → velocidades/s)
  Services/LiveTraffic.php      modo «en vivo» (1 muestra/s, 5 min) desde /var/lib/antiddos/live.json o Redis
  Services/MetricsService.php   historial (BD) 1h/6h/24h/7d con el mismo motor
  Services/IpIntel.php          origen + reputación + veredicto de una IP (caché, en segundo plano)
  Services/AutoBlocker.php      bloqueo automático de IPs «maliciosas» (opcional; 24 h / 7 d / para siempre; cron cada minuto + al instante)
  Services/ReputationLists.php  listas públicas de atacantes (descarga diaria, búsqueda binaria IPv4)
  Services/NetworkBlocks.php    bloqueo de OPERADORES (ASN) y PAÍSES: rangos de RIPEstat (respaldo RADB/ipdeny), agregados (máx. /8), feed «A», índice por /8 para ShieldGuard, regla de Cloudflare
  Models/ShieldNetBlock.php     tabla antiddos_net_blocks (kind asn|country, value, prefixes JSON, expires_at)
  Services/{IpMatcher,RateCounter}.php   utilidades
  Models/Shield{Setting,Block,Event,Agent,Metric}.php
  Console/Commands/ShieldCommand.php      `php artisan antiddos:manage <acción>`
  config/antiddos.php                     valores por defecto de TODOS los ajustes
  database/migrations/*                   6 migraciones (settings, blocks, events, agents, metrics + detalle + drop optimizaciones, net_blocks)
  resources/views/admin/index.blade.php   LA pantalla (HTML + CSS + JS de la gráfica, todo en un archivo)
  routes/{web,api}.php
```

---

## 4. Arquitectura (cómo encaja todo)

### 4.1 Agente de nodo (`scripts/antiddos-fw.sh`)
- Config: `/etc/antiddos/agent.conf` (se carga con `load_conf`; claves permitidas en `set_conf`). El **panel puede cambiar** `TCP_PORTS, UDP_PORTS, CONN_LIMIT, SYN_RATE, UDP_RATE, BAN_SECONDS, ATTACK_MODE, BAN_MODE` con líneas `C CLAVE VALOR` del feed (`apply_remote_setting`, valida cada valor). `-` = vacío.
- **iptables**: cadenas propias, sin tocar Docker. `raw/PREROUTING → ANTIDDOS` (lista blanca → bloqueos → malformados → ICMP → contadores → límites SYN/SSH/UDP/web) y `mangle/PREROUTING → ANTIDDOS_M` (connlimit). Una cadena `ANTIDDOS_D_{BAN,MAL,ICMP,UDP,WEB,SLIM}` por **motivo de descarte** (cada una solo hace DROP; así se cuentan). Cadenas vacías `ANTIDDOS_C{TCP,UDP,SYN}` solo cuentan paquetes.
- **ipset**: `vs_white`, `vs_white_feed`, `vs_feed` (bloqueos del panel), `vs_asn` (rangos de operadores/países, líneas `A` del feed; cuenta como `d_ban`), y un set por motivo de bloqueo local: `vs_ban` (flood SYN), `vs_ban_ssh`, `vs_ban_man` (manual). `sync_feed` carga todo con **`ipset -exist restore`** en sets `*_tmp` + `swap`; si el cuerpo no tiene ninguna línea `B/W/A/C` (error de PHP) o falla el restore, conserva los sets anteriores. Las líneas `A` con máscara < /8 se rechazan.
- **Endurecido en 1.9.0**: `hashlimit` con `--hashlimit-htable-max 262144` (`HL_BIG`) / 65536 (`HL_SMALL`); cadena `ANTIDDOS_AMP` (UDP desde puertos de amplificación `AMP_SPORTS` hacia puertos protegidos → `d_udp`); en `ATTACK_MODE` límite de SYN por /24 (`vs_syn24`, 4× el límite, solo frena); mangle `ANTIDDOS_M_INV` (TCP `ctstate INVALID` a puertos protegidos → cuenta como `d_mal`); sysctl `nf_conntrack_tcp_loose=0`, `tcp_timeout_established=21600`, `netdev_max_backlog=65536`, `netdev_budget`, `tcp_max_syn_backlog=65536`.
- **Modo `ATTACK_MODE`**: NO cambia los límites base. Añade reglas extra (conexiones nuevas/s a la mitad, por /24, UDP a la mitad, connlimit a la mitad) **solo para IPs fuera de `vs_trusted`**; solo frena. `vs_trusted` se aprende siempre (sesión ESTABLISHED, 6 h). Puertos reservados (SSH, 80, 443, Wings) se excluyen de la protección (`reserved_ports`); las reglas por puerto se generan por grupos de ≤15 (`ports_chunks`).
- **Política `BAN_MODE`**: `monitor` (nunca banea), `balanced` (**por defecto**: frena el exceso y banea a 3× el límite), `strict` (banea al primer exceso).
- **Servicios systemd**: `antiddos-fw` (reglas al arrancar), `antiddos-sync.timer` (cada 30 s: `cmd_sync` → feed + latido), `antiddos-live` (`cmd_watch`: contadores **cada 1 s**; escribe `/var/lib/antiddos/live.json` o hace POST `/api/antiddos/live` si el panel es remoto).
- **Datos que lee**: `read_counters` (13 números: tcp udp syn tcp_b udp_b + 6 motivos + connlimit + web + bytes frenados), `read_net` (RX/TX de la tarjeta `/proc/net/dev`, como OVH), `top_ips_json` (conntrack), `fw_bans_json`.
- Panel local: `PANEL_DIR` en `agent.conf` → usa `php artisan antiddos:manage feed|heartbeat` como usuario web (sin red ni clave). Panel remoto: `PANEL_URL` + `API_KEY` por HTTPS.

### 4.2 Panel
- **Escudo web** (`ShieldGuard`, middleware GLOBAL añadido tras TrustProxies): cuenta peticiones/IP en Redis; exentos `api/remote/*`, `api/antiddos/*`, assets, IPs internas, **Cloudflare** (si el panel ve IP de Cloudflare = falta TRUSTED_PROXIES → falla «abierto»), infraestructura y lista blanca. Falla abierto ante cualquier excepción.
- **Operadores/países** (`NetworkBlocks`): no deja bloquear redes que contengan la IP del admin (salvo en lista blanca), IPs de infraestructura (= el operador de tu propio servidor) o rangos de Cloudflare; ASN 13335/209242 prohibidos; > 4 M de IPs pide `force`. `ShieldGuard` responde 403 con `match()` (1 GET de caché por petición + 1 por /8 con bloqueos). Refresco diario 05:40 (`refresh-nets`).
- **Feed** (`BlockManager::feed`): texto plano `B ip` (bloqueo), `A cidr` (rango de operador/país), `W ip/cidr` (lista blanca + nodos/agentes/panel automáticamente), `C CLAVE VALOR` (ajustes). Los puertos enviados = manuales ∪ detectados (`MinecraftPorts::plan`) **solo si el firewall está «gestionado»** (`fw_tcp_ports` ≠ ''). Nunca se envía `TCP_PORTS=-`.
- **Tráfico**: `trafficData` (JSON) → `LiveTraffic` o `MetricsService` → `TrafficEngine`. Vistas `network` (Mbit/s RX/TX/frenado), `packets` (pps), `reasons` (apilado por motivo). Reglas de veracidad: velocidad real por tramo ponderada por tiempo, **huecos = null** (nunca picos falsos), reinicios de contador detectados, suma de nodos. El JS pide cada ~1 s en vivo (15 s si no se mira la pestaña) y marca «sin datos recientes» honestamente.
- **Estado** (`HealthCheck`): tablas, escudo, clave, caché Redis, IP real (Cloudflare/privada), listas de reputación, y el agente de cada nodo (por IP del FQDN o por host; con 1 nodo y 1 agente se emparejan solos).
- **Puertos**: `PortAdvisor` valora; `PortChanger` mueve la allocation (misma IP/nodo, libera la antigua, `sync()` a Wings, reinicio opcional). Rango recomendado 20000–32000 (configurable en la UI), evita 8000–8999 y puertos por defecto.
- **IpIntel**: geo vía ipwho.is (respaldo ipapi.co); listas públicas (Blocklist.de, Spamhaus DROP, FireHOL L1, CINS, Tor); AbuseIPDB opcional (clave cifrada con `Crypt`). Veredicto `clean|suspicious|malicious|unknown`; **nunca «normal» sin poder comprobarlo**. `peek()` no bloquea: encola la consulta con `app()->terminating`.
- **Falsos positivos**: `SafeIps::protectedReason` impide bloquear (manual, CLI, escudo web) nodos, agentes, panel, Cloudflare, lista blanca, privadas y la IP del admin. Botón «Es legítima» = lista blanca + desbloqueo.

### 4.3 Base de datos (prefijo `antiddos_`)
`settings` (clave/valor; cache), `blocks` (IPs bloqueadas por el panel), `net_blocks` (operadores/países bloqueados, desde 1.9.0), `events`, `agents` (último latido + payload JSON), `metrics` (muestras ~25 s: contadores acumulados incl. RX/TX y motivos). Tabla `optimizations` ya no existe (migración la borra).

---

## 5. CLI (`antiddos`, como root)

```
install-all [--tcp= --udp=]   panel + nodo en la misma máquina (genera clave sola)
install | reinstall | update [--force] | uninstall [--drop-data] | status | version | apikey | info | prune
node-install [--tcp= --udp= --web= --ssh= --panel-url= --key= --panel-dir=] | node-uninstall | node-status
fw status|apply|remove|top|sync|set CLAVE VALOR|white-add|white-del|ban|unban|bans     attack-on | attack-off
ban IP [min] | unban IP | bans | whitelist add|del|list
ip 203.0.113.7                análisis completo (origen, reputación, ¿se puede bloquear?)  (enlazado en el gestor desde 1.9.0)
auto-block                    una pasada del bloqueo automático (el cron la lanza cada minuto si está activado)
asn block|unblock|list|refresh AS199585 [--hours= --reason= --force]   country block|unblock|list PL
nginx-on|nginx-off|nginx-status   autoupdate-on|autoupdate-off
```
Artisan: `php artisan antiddos:manage info|apikey|purge|prune|feed|heartbeat|ip|refresh-lists|ban|unban|bans|whitelist|asn|country|refresh-nets`.

---

## 6. Convenciones de código

- Comentarios y textos de UI en **español**; identificadores en inglés/español técnico existente. Imita el estilo del archivo que tocas (PHP: `declare` no se usa en servicios; docblocks `@param array<...>`).
- **Tema-agnóstico**: la UI hereda colores del tema del panel (sin fondos fijos); CSS bajo `.ad`, variables `--ok/--warn/--err/--pri/--line`. Sin librerías externas (gráfica en `<canvas>` propio).
- Cada cambio funcional: **subir `VERSION` y `extension.json` a la vez**, actualizar `README.md`, commit en español descriptivo con `Co-Authored-By`, `git push origin main`.
- Siempre sanear datos que vienen de agentes (`AgentRegistry`, `LiveTraffic::normalize`); todo valor que acaba en `agent.conf` se valida con regex (se carga como shell).
- Un fallo de una función auxiliar (caché, BD sin migrar, red) **nunca** debe romper una petición del panel.

---

## 7. Instalar / actualizar (para dar al usuario)

```bash
# Primera vez (panel + Wings en la misma máquina), como root:
bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) install-all
# Actualizar a lo último:
sudo antiddos update --force
# Comprobar:
sudo antiddos status ; systemctl is-active antiddos-live
```
`raw.githubusercontent.com` **cachea ~5 min**: tras un push puede servir la versión vieja (probar con otra rama/commit o esperar). Si `antiddos update` falla por `origin/main` desconocido: `git -C /opt/antiddos fetch --depth 1 origin +refs/heads/main:refs/remotes/origin/main`.

---

## 8. Cómo verificar sin PHP ni Linux (hazlo SIEMPRE antes de commitear)

> **PC «ricard» (sesión de 1.9.0)**: `git` NO está en el PATH; usa `C:\Users\ricard\AppData\Local\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`. En la misma carpeta, `usr\bin\sh.exe` es **bash 5.2** (sirve para `bash -n` y simular funciones del agente con `ipset` falso) y hay `awk.exe`. Sin Python. PHP real: descargar el zip portable de windows.php.net al scratchpad y ejecutarlo con `Start-Process` (con `&` desde PowerShell falla raro). `shellcheck` con `npm i shellcheck` (descarga el binario).

1. **Bash**: `bash -n install.sh antiddos.sh bin/antiddos scripts/*.sh`.
2. **PHP**: no hay `php`. Se usa el parser de Node: crear una carpeta temporal, `npm i php-parser` y un `check.js` que recorra los `.php` (excepto `*.blade.php`) con `new Engine({parser:{php8:true}}).parseCode(...)`. Esperado: «N files checked, 0 errors».
3. **JS de la pantalla**: extraer el `<script>…</script>` de `index.blade.php`, sustituir `@json(...)` por literales y `node --check`.
4. **Blade**: contar que `@if/@endif`, `@foreach/@endforeach`, `@forelse/@endforelse`, `{{ }}` y `<div>/</div>` están equilibrados (script Python con regex).
5. **Lógica**: portar a Python/bash con datos simulados las partes delicadas (p. ej. `read_counters` con listados falsos de iptables, búsqueda binaria de listas contra una referencia, `fw_bans_json` con `ipset` simulado). Ya hecho para: contadores, RX/TX, top IPs, listas de reputación, bans.
6. Nunca digas «probado en producción»: di «sintaxis verificada + simulación».

---

## 9. Trampas conocidas (te ahorrarán horas)

- **Herramienta Bash en Windows**: los *heredocs* con comillas/apóstrofes largos suelen romper («unexpected EOF»). **Crea los scripts de parcheo con la herramienta Write** (p. ej. `scratchpad/xx.py`) y ejecútalos con `python`. Usa una función `sub(path, old, new)` con `assert old in s` para que un cambio fallido grite.
- En Python, las cadenas con `\E`, `\T` generan `SyntaxWarning` pero **funcionan** (la barra se conserva). Cuidado con ejecutar dos veces un script de parche: duplica bloques (pasó con `config`).
- `Write` exige haber **leído antes** un archivo modificado por `sed`/scripts: haz un `Read` corto antes de reescribirlo.
- Blade: `@json(...)` dentro de `<script>` está bien; no pongas `@` sueltos en el JS. `{{ }}` escapa HTML (por eso los comandos con `<(` van dentro de `{{ }}`).
- `iptables multiport` admite 15 entradas; un rango cuenta como 2 (`MinecraftPorts::compact` lo respeta).
- Docker: `raw`/`mangle` PREROUTING se evalúan antes del DNAT → ven el puerto publicado.
- El comando de arranque, `server.properties`, etc. **ya no se tocan** (optimización eliminada).
- Contadores de iptables se **reinician** al reaplicar reglas (cambiar puertos/política): el motor lo trata como reinicio.
- No se puede borrar la rama por defecto de GitHub: hay que cambiar el default a `main` primero.
- `git` avisa «LF will be replaced by CRLF»: es normal (hay `.gitattributes` con `eol=lf` para `.sh/.php`).

---

## 10. Historial de la sesión (qué se hizo y por qué)

| Versión | Qué se hizo |
| --- | --- |
| 1.0.0 | Creación del plugin copiando el modelo de instalación de **ServerSplitter** (hermano en `../ServerSplitter`): `install.sh` → gestor → instalador, provider, parche de menú. Firewall de nodo (iptables+ipset+sysctl), escudo web, optimizador de Minecraft, nginx opcional. Nombre original «VexaShield». |
| — | **Renombrado** a «Anti-DDoS y Optimización» / `antiddos`. Repo en GitHub (privado→público). Problemas resueltos: 404 por repo privado/inexistente, nombre de repo erróneo, caché de raw.githubusercontent, `install-all` ausente en `main`, `origin/main` desconocido en clones single-branch (arreglado con refspec explícito). Se probó instalar con *deploy key* SSH como alternativa a repo público. |
| — | Comando **`install-all`** (panel + nodo en la misma máquina, clave automática, sincronización local por artisan). |
| 1.1.0 | Panel rediseñado: pestañas, **estado de instalación** por nodo (latidos), firewall editable desde el panel (líneas `C`), modo «bajo ataque». |
| 1.2.0 | **Eliminada toda la optimización** (perfiles, optimizador, `optimize-host`, servicio `antiddos-tune`); migración que borra su tabla; el agente limpia lo antiguo. |
| 1.3.0 | **Detección automática de puertos** de servidores de Minecraft (eggs por nombre o elegidos), puertos adicionales (Dynmap, voice chat…), compactación en rangos. Detectó 69 servidores del usuario. |
| 1.4.0–1.4.2 | **Gráfica de tráfico** + **página de Puertos** (valoración y cambio rápido/en bloque, rango configurable). Gráfica rehecha para ser **veraz** (huecos, tramos reales, estado «en directo» honesto). Aviso Cloudflare/TRUSTED_PROXIES (el usuario lo configuró). |
| 1.5.0–1.6.0 | **Modo en vivo**: servicio `antiddos-live`, 3 s → **1 s**. |
| 1.7.0 | Gráfica «como OVH»: **↓RX/↑TX de la interfaz**, paquetes/s, **motivos de frenado** apilados, unidades, suavizado, escala log, pausa, CSV, estadísticas, **top de IPs**. Motor único `TrafficEngine`. |
| 1.8.0 | **Origen y reputación de IPs** (país/ASN/listas públicas/AbuseIPDB) con veredicto; **política de bloqueo** (monitor/balanced/strict) para evitar falsos positivos; bloqueos del firewall con motivo y botón «Es legítima»; `SafeIps`. |
| 1.9.0 | Petición urgente: DDoS desde **AS199585** (TELNET Ropczyce, Polonia, ~2.300 IPs, «Operador / residencial») que cambia de IP. **Bloqueo de operadores (ASN) y países** desde el panel (cuadro rápido «Bloquear operador (ASN)» en la cabecera, visible en todas las pestañas; pestaña propia «Operadores (ASN)» = `#asn`; botón rojo «🚫 Bloquear ASxxxx» en Tráfico), CLI `asn`/`country`, feed `A`, set `vs_asn`, 403 en el panel, regla para Cloudflare WAF. **Firewall endurecido** para floods grandes (el usuario habló de «solicitudes de hasta 107 M»): tablas hashlimit grandes, amplificación UDP, TCP INVALID/ACK floods, /24 en modo ataque, sysctl, sync con `ipset restore`. Arreglado: `antiddos ip` no estaba enlazado en el gestor; `extension.json` estaba en 1.1.0. |
| 1.9.1 | **Optimización de consumo** (el usuario dijo que «Pterodactyl entero gasta 4.7 G»). Panel: `BlockManager::remaining` usa una clave de caché por IP (`antiddos:blk:ip:*` + `antiddos:blk:ready`, un `Cache::many` por petición) en vez del mapa entero; `MetricsService` con `toBase()->select()->cursor()` + caché 10/60 s; tablas top/fw_bans cacheadas 3 s; `ReputationLists` con resumen `antiddos:rep:meta:*` y memo por proceso. Nodo: acción artisan **`sync`** (latido + feed en un arranque de PHP); `sync_feed` se salta si md5(agent.conf + feed) no cambió (`/run/antiddos/feed.sum`, se borra en `apply`/`remove`); `cmd_watch` con muestras en array de bash, `read_net_var`/`now_ms_var` sin procesos, espera con `read -t` sobre una tubería, bloqueos contados cada 5 s con `ipset -t`; `read_counters` con un solo awk. |
| 1.9.2 | «¿La gráfica gasta mucha base de datos?»: `MetricsService::compact()` (artisan `compact-metrics`, cada hora al minuto 17): todo a resolución completa las últimas 26 h (`FULL_HOURS`), antes 1 muestra por tramo de 5 min (`THIN_EVERY`) por host, nada de más de 8 días (`KEEP_DAYS`, antes 14); la vista 7d usa `GAP_THINNED`=420 para no pintar huecos falsos. Recuerda hasta dónde aclaró (`antiddos:metrics:thinned`). `AgentRegistry::sample` ya no consulta la última fila: `Cache::add('antiddos:metric:last:<host>', 1, 25)`. Simulado con el motor real: 57.619 → 10.231 filas (2 nodos, 10 días), 24 h idéntica, 7 d 0,00 % de diferencia. |
| 1.10.0 | **Bloqueo automático de IPs maliciosas** (petición: «cuando determine la IP como maliciosa que directamente la bloquee» + «para siempre o solo 24 h»). `AutoBlocker` (apagado por defecto; ajustes `auto_block_malicious` y `auto_block_duration` = `24h`·`7d`·`forever`): `IpIntel::lookup` lo dispara al instante con veredicto `malicious` y el scheduler lanza `antiddos:manage auto-block` cada minuto (`runInBackground`, `when(enabled)` para no arrancar nada si está apagado; máx. 6 consultas nuevas y 40 s por pasada) sobre las `top_ips` de los agentes. Bloquea con `source='intel'` (etiqueta «Reputación») vía `BlockManager::block`, que sigue pasando por `SafeIps`. Solo `malicious` (listas de atacantes o AbuseIPDB ≥ 75); `suspicious` nunca. `antiddos ip` no bloquea (3.er parámetro `$autoBlock=false`). |
| 1.11.0 | **Arreglo del «limbo» del modo bajo ataque** (el usuario: «cuando lo activamos los servidores del hosting dejan de responder como si se quedasen en un limbo»). Causas halladas: (1) el modo ataque dividía por 3 los límites base de **todos** (conexiones simultáneas 30→10, SYN/s 15→5, UDP, bans ×4) y los paquetes sobrantes se **descartaban sin RST** (el cliente se queda esperando); (2) los rangos de puertos compactados por el panel (`8000:8092`) **contenían el 8080 de Wings**, así que las consolas/archivos del panel quedaban bajo los mismos límites. Arreglo en `antiddos-fw.sh`: set `vs_trusted` (IPs con sesión ESTABLISHED a puertos protegidos, 6 h, aprendido en `mangle`) → en modo ataque las reglas estrictas (`vs_synA`, `vs_syn24`, `vs_udpA`, connlimit a la mitad) llevan `! --match-set vs_trusted` y **solo frenan, sin bloquear**; los límites base ya no se tocan (solo ICMP=1); `reserved_ports()` (SSH, 80, 443, API y SFTP de Wings leídos de `/etc/pterodactyl/config.yml`, `EXCLUDE_PORTS`) se resta de TCP/UDP con `ports_minus()`; `ports_chunks()` reparte en reglas de ≤15 entradas (adiós al límite de multiport); `status` muestra jugadores conocidos y puertos efectivos. Verificado simulando `apply_rules` con iptables falso (72 reglas normal / 79 en ataque, solo 7 extra y todas con `! vs_trusted`) y `ports_*` con la lista real del usuario. |
| 1.11.1 | Tabla «Quién se está conectando» con muchas IPs en «Origen desconocido / Sin datos» y la IP del propio servidor como visitante. Causas: solo había 2 APIs gratuitas (ipwho.is, ipapi.co) que se saturan y el fallo se guardaba **10 min**; el agente no excluye la IP pública propia. Arreglo en `IpIntel`: ASN + titular por **RIPEstat `prefix-overview`** (fiable, sin clave) y país/ciudad por el primer servicio que responda de `ipwho`·`freeipapi`·`geojs`·`ripegeo`·`ipapi`; cada servicio que falla (429/403/5xx/timeout/límite) queda en pausa 5 min (`antiddos:intel:cool:<proveedor>`); sin geolocalización el estado es **`partial`** ("Origen no disponible") con TTL de 2 min en vez de 10; `recheck()` + ruta `intel.recheck` + botón «↻ Reintentar consulta» en detalles. `AdminController::topIps` omite `SafeIps::isInfrastructure` (nodos, panel, IPs de los agentes). |

Decisiones del usuario a recordar: quitar la optimización; una sola rama; el nombre se queda; quería ver **por qué** se bloquea cada cosa y evitar bloqueos sin sentido; quiere datos **precisos y veraces** en la gráfica.

---

## 11. Estado real y pendientes

- **Verificado por el usuario en su servidor**: v1.1.0 (Estado en verde, agente activo, bloqueo de prueba propagado panel→nodo). Cloudflare/`TRUSTED_PROXIES` configurado y comprobado.
- **Publicado pero sin confirmar en producción**: todo desde 1.2.0 (puertos auto, gráfica, en vivo, RX/TX, reputación, política). Falta que el usuario ejecute `sudo antiddos update --force` y reporte: ¿`systemctl is-active antiddos-live` = active?, ¿la gráfica «en vivo» se mueve?, ¿RX/TX se parece a OVH?, ¿aparecen país/veredicto?
- Ideas pendientes (no pedidas aún): suavizado/medias en la vista en vivo si «nerviosa»; renombrar a «Anti-DDoS» sin «Optimización» (el usuario decide); alertas (Discord/email) al detectar ataque; IPv6; borrar la rama vieja `feat/install-all-sync-local` cuando el usuario cambie el default a `main`; límite de 40 servidores por tanda en cambio de puertos.
- Si el usuario menciona «bloqueos sin sentido» concretos, pedir la IP y mirar `antiddos ip <IP>`, `antiddos fw bans` y la tabla «Bloqueos activos del firewall».
