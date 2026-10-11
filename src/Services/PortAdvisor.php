<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

class PortAdvisor
{
    public const SERVICE_PORTS = [
        20 => 'FTP', 21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3',
        143 => 'IMAP', 443 => 'HTTPS', 465 => 'SMTPS', 587 => 'SMTP', 993 => 'IMAPS', 995 => 'POP3S',
        2022 => 'SFTP de Wings', 3306 => 'MySQL/MariaDB', 5432 => 'PostgreSQL', 6379 => 'Redis', 8080 => 'Wings / web',
        8443 => 'HTTPS alternativo', 9000 => 'PHP-FPM / Portainer', 11211 => 'Memcached', 27017 => 'MongoDB',
    ];

    public const DEFAULT_PORTS = [25565 => 'Minecraft Java', 19132 => 'Minecraft Bedrock'];

    public const SAFE_MIN = 20000;
    public const SAFE_MAX = 32000;

    public const EPHEMERAL_FROM = 32768;

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

        if ($port >= 8000 && $port <= 8999 && !isset(self::SERVICE_PORTS[$port])) {
            $issues[] = ['level' => 'warn', 'text' => 'Rango típico de servicios web (8000-8999: HTTP alternativo, paneles, Dynmap en 8123…): los escáneres lo rastrean como web y puede chocar con otros servicios. Mejor 20000-32000.'];
        }

        if ($port >= 25500 && $port <= 25700 && !isset(self::DEFAULT_PORTS[$port])) {
            $issues[] = ['level' => 'warn', 'text' => 'Está en el rango que más barren los bots de Minecraft (25500-25700, alrededor del puerto por defecto).'];
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

    public function worst(array $issues): string
    {
        $levels = array_column($issues, 'level');

        return in_array('error', $levels, true) ? 'error' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }
}
