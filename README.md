# Anti-DDoS y Optimización para Pterodactyl

Autor: **Vexa Studio**

Anti-DDoS para servidores de Minecraft en Pterodactyl, en dos capas:

| Capa | Dónde se instala | Qué hace |
| --- | --- | --- |
| **Agente de nodo** (prioridad) | Cada máquina con **Wings** | Firewall `iptables` + `ipset` + `sysctl` que descarta SYN floods, UDP floods, paquetes malformados y limita las conexiones por IP **antes** de que gasten CPU. Recibe solo los bloqueos del panel. |
| **Escudo web** | En el **panel** | Limita las peticiones por IP al panel y bloquea a quien se pasa (con duración creciente por reincidencia). Frena la fuerza bruta del login. Opcionalmente, límites en nginx. |

- Panel de administración propio: `/admin/extensions/antiddos` (estado, protección, bloqueos y eventos).
- Comando `antiddos` con instalación, actualización, desinstalación y todo el control del firewall por consola.
- **Estado de la instalación en el propio panel**: la pestaña *Estado* dice si está «todo instalado y funcionando» o qué falta (tablas, caché, clave, y el agente de **cada nodo**), con el comando que lo arregla. Los agentes informan al panel cada ~30 s.
- **Gráfica de tráfico** (pestaña *Tráfico*): modo **«En vivo»** con un punto por segundo (últimos 5 min; el servicio `antiddos-live` del nodo lee los contadores cada segundo) y, además, historial de 1 h / 6 h / 24 h / 7 días. paquetes frenados, conexiones nuevas (SYN), TCP y UDP recibidos y IPs bloqueadas, por nodo o de todos, en 1 h / 6 h / 24 h / 7 días, con actualización automática cada 30 s. Los agentes envían sus contadores y el panel los guarda (se limpian solos con los días de retención).
- **Puertos poco recomendables** (pestaña *Puertos*): revisa el puerto de cada servidor de Minecraft y marca en rojo los reservados (<1024) o de servicios conocidos (SSH, web, MySQL, Redis, Wings…) y en naranja el puerto por defecto (25565/19132), el rango de puertos temporales de Linux (32768+) y los no protegidos por el firewall. Se cambian con un clic (puerto a elegir o uno recomendado al azar entre 20000 y 32000), uno a uno o en bloque, con reinicio opcional. El cambio mueve la asignación del servidor igual que el panel en *Build Configuration*.
- **Detección automática de puertos**: el panel reconoce los eggs de Minecraft (Paper, Spigot, Forge, Bedrock, BungeeCord…), toma el puerto de cada servidor y lo protege solo; se actualiza en menos de un minuto al crear o mover servidores. Opcionalmente protege también sus puertos adicionales (Dynmap, voice chat, query…) y puedes elegir qué eggs cuentan como Minecraft. Los puertos manuales se suman a los detectados.
- **Firewall editable desde el admin**: puertos TCP/UDP (`25565:25666`), límites por IP y **modo «bajo ataque»** se cambian en la pestaña *Protección* y se envían a todos los nodos sin tocar la consola. Una vez guardados, el panel manda: un `antiddos fw set` manual en el nodo se sobrescribe en la siguiente sincronización.
- Los bloqueos del panel (manuales o automáticos) **se propagan a todos los nodos** en menos de un minuto.

## Qué puede y qué no puede parar (importante)

Anti-DDoS y Optimización frena todo lo que se puede frenar **en tu propia máquina**: floods de SYN/UDP/ICMP de bajo y medio volumen, bots que abren cientos de conexiones, escaneos, fuerza bruta y abuso HTTP del panel.

**No puede** parar un ataque *volumétrico* que sature el enlace de tu proveedor (decenas de Gbps): cuando la tubería está llena, el tráfico ya ha llegado antes de que ningún firewall del servidor lo vea. Para eso necesitas protección anti-DDoS en la red del hosting (OVH Game, Hetzner, etc.) o un proxy de Minecraft con mitigación (TCPShield, Cloudflare Spectrum). Anti-DDoS y Optimización se combina bien con ellos: pon en la lista blanca/`--web` lo que corresponda y deja que el agente cubra el resto.

Otras limitaciones conocidas:

- Solo IPv4 (el firewall no toca ip6tables).
- Los floods de **UDP** (Bedrock/Geyser) solo se descartan, no se bloquea la IP de origen, porque suele estar falsificada.
- Los bloqueos por SYN flood asumen que una IP real que hace cientos de SYN/s es hostil; son temporales (15 min por defecto).

## Requisitos

- Pterodactyl Panel 1.11.x (Laravel 10) o equivalente con el namespace `Pterodactyl\`.
- PHP 8.1+ y acceso por consola como root al servidor del panel y a cada nodo.
- Nodos con `iptables` e `ipset` (el instalador los instala con `apt`/`dnf`/`yum` si faltan) y kernel con tabla `raw`/`mangle` (VPS KVM o dedicado; en contenedores OpenVZ/LXC suele no estar permitido).
- `curl` y `git` (el gestor instala `git` automáticamente).
- El `schedule:run` del panel en cron (ya lo exige Pterodactyl) para la limpieza diaria.

## Instalación

**Panel y Wings en la misma máquina (servidor dedicado único)** — un solo comando, como root. Instala la extensión, el firewall del nodo, genera la clave de la API y se la entrega al agente solo (además el agente lee los bloqueos del panel en local, sin red ni clave):

    bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) install-all

Añade `--tcp=25565 --udp=19132` para saltarte las preguntas de puertos.

Si el panel y los nodos están en **máquinas distintas**, usa los dos comandos siguientes (uno en el panel, otro en cada nodo).

**En el panel** (instala la extensión, genera la clave de la API y añade el menú):

    bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) install

**En cada nodo con Wings** (firewall anti-DDoS):

    bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) node-install \
        --tcp=25565 --panel-url=https://panel.tudominio.com --key=LA_CLAVE_DE_LA_API

- `--tcp` puertos TCP de Minecraft a proteger (coma o rangos: `25565,25570:25600`; máx. 15 entradas). Si no lo indicas, el instalador te lo pregunta.
- `--udp` puertos UDP (Bedrock/Geyser: `19132`).
- `--web` puertos de la web (`80,443`) para limitar SYN por IP. **Déjalo vacío si el panel está detrás de Cloudflare/proxy** (todos los usuarios llegarían con la misma IP).
- `--ssh` puerto SSH a proteger de fuerza bruta (se autodetecta).
- `--panel-url` y `--key` activan la sincronización de bloqueos con el panel. La clave se muestra al final de la instalación del panel; si la perdiste: `sudo antiddos apikey` (o el botón en el admin).

> Si el panel está en **otra máquina**, añade su IP a la lista blanca del nodo: `sudo antiddos fw white-add IP_DEL_PANEL`.

Qué hace el instalador del panel:

1. `install.sh` descarga el gestor `antiddos.sh` desde el repositorio.
2. El gestor clona el código en `/opt/antiddos` y ejecuta `scripts/addon-install.sh`.
3. Se copia `src/` a `app/Extensions/AntiDDoS`, se registra el ServiceProvider (con copia de seguridad), se añade el enlace al menú del admin, se limpian cachés y se ejecutan las migraciones.
4. Se genera la clave de la API si no existe (nunca se pisa en actualizaciones).
5. Se guarda el estado en `/usr/local/share/antiddos` y se instala el comando `/usr/local/bin/antiddos`.

`composer.json` **no se modifica**: el namespace `Pterodactyl\Extensions\*` ya lo cubre el autoload del panel.

Qué hace el instalador del nodo (`scripts/node-agent.sh`):

1. Instala `iptables`, `ipset` y `curl` si faltan.
2. Copia el firewall a `/usr/local/sbin/antiddos-fw` y crea `/etc/antiddos/agent.conf` (se conserva en reinstalaciones).
3. Crea y activa los servicios systemd: `antiddos-fw.service` (reglas al arrancar), `antiddos-sync.timer` (descarga bloqueos cada 30 s).

Si ya estaba instalado, el instalador del panel ofrece **Reinstalar** (no toca la base de datos), **Actualizar** o **Cancelar**, igual que ServerSplitter.

## Comandos

| Comando | Descripción |
| --- | --- |
| `sudo antiddos install` | Instala la extensión en el panel (si ya está, ofrece reinstalar/actualizar) |
| `sudo antiddos reinstall` | Recopia archivos y parches **sin tocar la base de datos** |
| `sudo antiddos update` | Actualiza panel y/o agente de nodo (no hace nada si ya está al día) |
| `sudo antiddos update --force` | Reinstala aunque no haya cambios |
| `sudo antiddos status` | Estado de la instalación, provider, CLI, agente y auto-update |
| `sudo antiddos apikey` | Genera una clave de API nueva (`--key=CLAVE` para fijar una) |
| `sudo antiddos info` | Configuración y contadores |
| `sudo antiddos prune` | Limpia bloqueos caducados y eventos antiguos |
| `sudo antiddos uninstall` | Desinstala todo (conserva las tablas) |
| `sudo antiddos uninstall --drop-data` | Desinstala y **borra las tablas** |
| `sudo antiddos autoupdate-on` / `-off` | Actualización automática diaria (04:00) |
| **Nodo** | |
| `sudo antiddos node-install [--tcp= --udp= --web= --ssh= --panel-url= --key=]` | Instala el firewall anti-DDoS |
| `sudo antiddos node-uninstall` | Quita el agente (conserva `/etc/antiddos`) |
| `sudo antiddos node-status` | Estado del firewall |
| `sudo antiddos attack-on` / `attack-off` | **Modo «bajo ataque»**: límites ~3 veces más estrictos |
| `sudo antiddos fw status` | Estado, límites y paquetes descartados |
| `sudo antiddos fw top` | IPs con más conexiones a tus puertos |
| `sudo antiddos fw apply` / `remove` | Reaplica / quita todas las reglas |
| `sudo antiddos fw set CLAVE VALOR` | Cambia un ajuste (luego `fw apply`) |
| `sudo antiddos fw sync` | Descarga ya los bloqueos del panel |
| **Bloqueos** (firewall local y/o panel según lo que haya en la máquina) | |
| `sudo antiddos ban 1.2.3.4 [minutos]` | Bloquea una IP (`0`/vacío = permanente) |
| `sudo antiddos unban 1.2.3.4` | Desbloquea |
| `sudo antiddos bans` | Lista los bloqueos activos |
| `sudo antiddos whitelist add\|del\|list [IP\|CIDR]` | Lista blanca |
| **Nginx (panel)** | |
| `sudo antiddos nginx-on [--rate=20 --burst=100 --conn=50]` | Límites de peticiones en nginx (capa 7) |
| `sudo antiddos nginx-off` / `nginx-status` | Quita / muestra |

Opciones comunes: `--force`/`-y`, `--drop-data`, `--panel=RUTA`, `--user=USUARIO`, `--key=CLAVE`.
Variables de entorno: `PANEL_DIR`, `WEB_USER`, `REPO_URL`, `REPO_BRANCH`, `SRC_DIR`.

## Cómo funciona el firewall del nodo

Todo vive en dos cadenas propias (`ANTIDDOS` en la tabla `raw`, `ANTIDDOS_M` en `mangle`) y en sets de `ipset`, así que **no toca tus reglas ni las de Docker**:

1. **`raw/PREROUTING`** (antes de conntrack, por lo que un ataque no llena la tabla de conexiones): lista blanca → bloqueos (locales y del panel) → fragmentos y combinaciones ilegales de flags TCP → límite de ping → **límite de SYN por IP** (si se supera, la IP se bloquea `BAN_SECONDS` con `-j SET --add-set … --timeout`, sin procesos externos) → límite SSH → límite UDP.
2. **`mangle/PREROUTING`**: **límite de conexiones simultáneas por IP** (`connlimit`).
3. **`sysctl`**: SYN cookies, backlog, tiempos de conntrack, `rp_filter`, etc.

Funciona con los puertos que publica Docker/Wings porque `raw` y `mangle` se evalúan antes del DNAT: el puerto que ve es el publicado.

Valores por defecto (en `/etc/antiddos/agent.conf`):

| Clave | Defecto | Significado |
| --- | --- | --- |
| `TCP_PORTS` | `25565` | Puertos TCP protegidos |
| `UDP_PORTS` | *(vacío)* | Puertos UDP protegidos |
| `WEB_PORTS` | *(vacío)* | Web del panel (límite SYN, solo descarta) |
| `CONN_LIMIT` | `30` | Conexiones simultáneas por IP |
| `SYN_RATE` / `SYN_BURST` | `15` / `30` | SYN nuevos por segundo y ráfaga antes de bloquear |
| `UDP_RATE` / `UDP_BURST` | `400` / `800` | Paquetes UDP por segundo y ráfaga |
| `BAN_SECONDS` | `900` | Duración del bloqueo automático |
| `ATTACK_MODE` | `0` | `1` = límites ÷3 y bloqueos ×4 |
| `STATIC_WHITELIST` | redes privadas | Nunca se bloquean (127/8, 10/8, 172.16/12, 192.168/16) |

Si hay muchos jugadores tras una misma IP (cibercafé, colegio, CGNAT móvil) sube `CONN_LIMIT` o añade el rango a la lista blanca.

Los bloqueos automáticos se registran en el journal: `journalctl -k | grep ANTIDDOS`.

## Escudo web del panel

- Se registra como middleware global, **después** de `TrustProxies` (ve la IP real detrás de nginx/Cloudflare si `TRUSTED_PROXIES` está bien configurado) y antes de sesión y base de datos: un bloqueado recibe un `429` casi gratis.
- No cuenta la API de Wings (`api/remote/*`), el feed de los nodos ni los assets estáticos. Las IPs privadas/loopback y las de la lista blanca nunca se bloquean.
- Por defecto: 480 peticiones/minuto y 15 intentos de login/minuto por IP; primer bloqueo de 30 min que se **duplica en cada reincidencia** hasta 24 h.
- Si el panel ve siempre la IP de un proxy interno (porque `TRUSTED_PROXIES` no está configurado) todas las peticiones parecen internas y el escudo no actúa: falla «abierto» a propósito, para no bloquear a todos tus usuarios.
- Un fallo del escudo (caché caída, migraciones pendientes…) nunca tumba el panel: la petición pasa.

Para cortar ataques HTTP **antes de PHP**, activa también los límites de nginx: `sudo antiddos nginx-on` (valida con `nginx -t` y se deshace solo si falla; se niega a activarse si detecta un proxy delante sin `set_real_ip_from`).

## Desinstalación

    sudo antiddos uninstall               # conserva las tablas
    sudo antiddos uninstall --drop-data   # y las borra


## Solución de problemas

| Síntoma | Qué mirar |
| --- | --- |
| Jugadores legítimos no pueden entrar | `antiddos fw status` y `fw bans`; `antiddos unban IP`; sube `CONN_LIMIT`/`SYN_RATE` o usa `whitelist add`. Prueba `attack-off`. |
| Wings pierde la conexión con el panel | Añade la IP del panel a la lista blanca del nodo: `antiddos fw white-add IP`. |
| El escudo web bloquea a todos | Revisa `TRUSTED_PROXIES` en `.env` del panel y `set_real_ip_from` en nginx. Apágalo en el admin mientras tanto. |
| `fw apply` dice que falta la tabla raw | El kernel/VPS no permite iptables raw (OpenVZ/LXC). Necesitas KVM o dedicado. |
| El menú no aparece | El middleware lo inyecta solo; si no, `sudo antiddos reinstall`. O entra por `/admin/extensions/antiddos`. |
| CSF/LFD instalado | Puede vaciar las reglas de iptables; usa solo uno de los dos. |

## Licencia

MIT · Vexa Studio
