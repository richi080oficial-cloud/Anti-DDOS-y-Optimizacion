<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

/**
 * Valora si un puerto es una buena eleccion para un servidor de Minecraft.
 *
 * Niveles: error (cambialo ya), warn (mejorable). Sin avisos = recomendado.
 */
class PortAdvisor
{
    /** Puertos de servicios habituales: nunca deberia usarlos un servidor de juego. */
    public const SERVICE_PORTS = [
        20 => 'FTP', 21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3',
        143 => 'IMAP', 443 => 'HTTPS', 465 => 'SMTPS', 587 => 'SMTP', 993 => 'IMAPS', 995 => 'POP3S',
        2022 => 'SFTP de Wings', 3306 => 'MySQL/MariaDB', 5432 => 'PostgreSQL', 6379 => 'Redis', 8080 => 'Wings / web',
        8443 => 'HTTPS alternativo', 9000 => 'PHP-FPM / Portainer', 11211 => 'Memcached', 27017 => 'MongoDB',
    ];

    /** Puertos por defecto de Minecraft: los que mas escanean los bots. */
    public const DEFAULT_PORTS = [25565 => 'Minecraft Java', 19132 => 'Minecraft Bedrock'];

    /** Rango en el que se proponen los puertos nuevos (lejos de los escaneos habituales y de los puertos temporales de Linux). */
    public const SAFE_MIN = 20000;
    public const SAFE_MAX = 32000;

    /** Inicio del rango de puertos temporales de Linux (net.ipv4.ip_local_port_range). */
    public const EPHEMERAL_FROM = 32768;

    /**
     * @param array{tcp: string, udp: string}|null $protected puertos protegidos por el firewall (null = no se comprueba)
     * @param array<int, int> $reserved puertos de Wings del nodo (daemonListen, daemonSFTP)
     * @return array<int, array{level: string, text: string}>
     */
    public function assess(int $port, bool $bedrock = false, ?array $protected = null, array $reserved = []): array
    {
        $issues = [];

        if ($port < 1024) {
            $issues[] = ['level' => 'error', 'text' => "Puerto reservado del sistema (<1024): no es adecuado para un servidor de juego."];
        }

        if (isset(self::SERVICE_PORTS[$port]) || in_array($port, $reserved, true)) {
            $name = self::SERVICE_PORTS[$port] ?? 'Wings';
            $issues[] = ['level' => 'error', 'text' => "Lo usa un servicio habitual ({$name}): puede chocar y es un objetivo de escaneos."];
        }

        if (isset(self::DEFAULT_PORTS[$port])) {
            $issues[] = ['level' => 'warn', 'text' => 'Es el puerto por defecto (' . self::DEFAULT_PORTS[$port] . '): los bots lo escanean constantemente. Mejor un puerto alto y un registro SRV.'];
        }

        if ($port >= self::EPHEMERAL_FROM) {
            $issues[] = ['level' => 'warn', 'text' => 'Está en el rango de puertos temporales de Linux (32768+): puede coincidir con conexiones salientes del sistema.'];
        }

        if ($protected !== null) {
            $list = (new MinecraftPorts())->parse($bedrock ? $protected['udp'] : $protected['tcp']);

            $covered = false;
            foreach ($list as [$from, $to]) {
                if ($port >= $from && $port <= $to) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $issues[] = ['level' => 'warn', 'text' => 'No está dentro de los puertos que protege el firewall.'];
            }
        }

        return $issues;
    }

    /** Peor nivel de una lista de avisos: error | warn | ok. */
    public function worst(array $issues): string
    {
        $levels = array_column($issues, 'level');

        return in_array('error', $levels, true) ? 'error' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }
}
