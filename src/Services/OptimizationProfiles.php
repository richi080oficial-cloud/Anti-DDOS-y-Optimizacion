<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

/**
 * Perfiles de optimizacion para servidores de Minecraft.
 *
 * Cada perfil lista, por archivo, las claves que se cambian. Regla de oro del
 * optimizador: SOLO se modifican claves que ya existen en el archivo (Paper,
 * Spigot y Bukkit escriben todas sus claves al arrancar), de modo que un valor
 * que no existe en esa version del servidor nunca se inventa.
 *
 * Los valores siguen las recomendaciones habituales de la comunidad (guia de
 * Paper, "Optimising your server"): lo importante cuando hay muchos jugadores
 * es reducir las distancias de simulacion/visibilidad, el rango de actividad y
 * seguimiento de entidades, y los limites de aparicion de mobs.
 */
final class OptimizationProfiles
{
    public const DEFAULT = 'balanced';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $commonProperties = [
            'network-compression-threshold' => 256,
            'sync-chunk-writes' => 'false',
        ];

        return [
            'light' => [
                'label' => 'Ligero',
                'description' => 'Servidores pequeños o con poca carga. Casi no cambia la jugabilidad: mejora el GC y recorta un poco las distancias.',
                'properties' => $commonProperties + [
                    'view-distance' => 10,
                    'simulation-distance' => 8,
                    'entity-broadcast-range-percentage' => 100,
                ],
                'spigot' => [
                    'world-settings.default.mob-spawn-range' => 6,
                    'world-settings.default.merge-radius.item' => 4.0,
                    'world-settings.default.merge-radius.exp' => 6.0,
                ],
                'bukkit' => [
                    'chunk-gc.period-in-ticks' => 400,
                ],
                'paper_world' => [
                    'collisions.max-entity-collisions' => 4,
                    'environment.optimize-explosions' => true,
                ],
            ],

            'balanced' => [
                'label' => 'Equilibrado',
                'description' => 'Recomendado para la mayoria de servidores (10-40 jugadores). Notable mejora de TPS sin cambios visibles.',
                'properties' => $commonProperties + [
                    'view-distance' => 8,
                    'simulation-distance' => 6,
                    'entity-broadcast-range-percentage' => 80,
                ],
                'spigot' => [
                    'world-settings.default.mob-spawn-range' => 6,
                    'world-settings.default.entity-activation-range.animals' => 16,
                    'world-settings.default.entity-activation-range.monsters' => 24,
                    'world-settings.default.entity-activation-range.raiders' => 48,
                    'world-settings.default.entity-activation-range.misc' => 8,
                    'world-settings.default.entity-activation-range.water' => 16,
                    'world-settings.default.entity-activation-range.villagers' => 16,
                    'world-settings.default.entity-activation-range.flying-monsters' => 32,
                    'world-settings.default.merge-radius.item' => 4.0,
                    'world-settings.default.merge-radius.exp' => 6.0,
                    'world-settings.default.entity-tracking-range.players' => 48,
                    'world-settings.default.entity-tracking-range.animals' => 48,
                    'world-settings.default.entity-tracking-range.monsters' => 48,
                    'world-settings.default.entity-tracking-range.misc' => 32,
                    'world-settings.default.entity-tracking-range.other' => 64,
                ],
                'bukkit' => [
                    'spawn-limits.monsters' => 50,
                    'spawn-limits.animals' => 8,
                    'spawn-limits.water-animals' => 5,
                    'spawn-limits.water-ambient' => 10,
                    'spawn-limits.ambient' => 5,
                    'chunk-gc.period-in-ticks' => 400,
                ],
                'paper_world' => [
                    'chunks.max-auto-save-chunks-per-tick' => 8,
                    'chunks.prevent-moving-into-unloaded-chunks' => true,
                    'collisions.max-entity-collisions' => 2,
                    'environment.optimize-explosions' => true,
                ],
            ],

            'crowded' => [
                'label' => 'Muchos jugadores',
                'description' => 'Para servidores llenos (40+ jugadores) o con poca CPU por jugador. Recorta distancias, actividad de entidades y mobs; cambia un poco la jugabilidad (menos mobs, redstone alternativa).',
                'properties' => $commonProperties + [
                    'view-distance' => 6,
                    'simulation-distance' => 4,
                    'entity-broadcast-range-percentage' => 60,
                ],
                'spigot' => [
                    'world-settings.default.mob-spawn-range' => 4,
                    'world-settings.default.entity-activation-range.animals' => 12,
                    'world-settings.default.entity-activation-range.monsters' => 20,
                    'world-settings.default.entity-activation-range.raiders' => 48,
                    'world-settings.default.entity-activation-range.misc' => 4,
                    'world-settings.default.entity-activation-range.water' => 12,
                    'world-settings.default.entity-activation-range.villagers' => 16,
                    'world-settings.default.entity-activation-range.flying-monsters' => 32,
                    'world-settings.default.merge-radius.item' => 4.0,
                    'world-settings.default.merge-radius.exp' => 6.0,
                    'world-settings.default.entity-tracking-range.players' => 48,
                    'world-settings.default.entity-tracking-range.animals' => 32,
                    'world-settings.default.entity-tracking-range.monsters' => 32,
                    'world-settings.default.entity-tracking-range.misc' => 24,
                    'world-settings.default.entity-tracking-range.other' => 48,
                ],
                'bukkit' => [
                    'spawn-limits.monsters' => 35,
                    'spawn-limits.animals' => 6,
                    'spawn-limits.water-animals' => 4,
                    'spawn-limits.water-ambient' => 5,
                    'spawn-limits.ambient' => 3,
                    'chunk-gc.period-in-ticks' => 400,
                ],
                'paper_world' => [
                    'chunks.max-auto-save-chunks-per-tick' => 6,
                    'chunks.prevent-moving-into-unloaded-chunks' => true,
                    'collisions.max-entity-collisions' => 2,
                    'environment.optimize-explosions' => true,
                    'environment.treasure-maps.enabled' => false,
                    'misc.redstone-implementation' => 'ALTERNATE_CURRENT',
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Perfil base + ajustes personalizados del formulario. Todos opcionales:
     * view_distance, simulation_distance, entity_broadcast (%), mobs
     * (profile|reduced|minimum), redstone (bool), no_treasure_maps (bool),
     * collisions (int).
     *
     * @param array<string, mixed> $o
     */
    public static function resolve(string $key, array $o = []): ?array
    {
        $p = self::get($key);

        if ($p === null) {
            return null;
        }

        $int = static fn (string $k, int $min, int $max): ?int => isset($o[$k]) && is_numeric($o[$k]) && $o[$k] !== ''
            ? max($min, min($max, (int) $o[$k]))
            : null;

        if (($v = $int('view_distance', 2, 32)) !== null) {
            $p['properties']['view-distance'] = $v;
        }

        if (($v = $int('simulation_distance', 2, 32)) !== null) {
            $p['properties']['simulation-distance'] = $v;
        }

        if (($v = $int('entity_broadcast', 10, 100)) !== null) {
            $p['properties']['entity-broadcast-range-percentage'] = $v;
        }

        if (($v = $int('collisions', 0, 8)) !== null) {
            $p['paper_world']['collisions.max-entity-collisions'] = $v;
        }

        $mobs = [
            'reduced' => ['monsters' => 35, 'animals' => 6, 'water-animals' => 4, 'water-ambient' => 5, 'ambient' => 3, 'range' => 4],
            'minimum' => ['monsters' => 20, 'animals' => 4, 'water-animals' => 3, 'water-ambient' => 3, 'ambient' => 2, 'range' => 3],
        ][$o['mobs'] ?? ''] ?? null;

        if ($mobs !== null) {
            foreach (['monsters', 'animals', 'water-animals', 'water-ambient', 'ambient'] as $type) {
                $p['bukkit']['spawn-limits.' . $type] = $mobs[$type];
            }

            $p['spigot']['world-settings.default.mob-spawn-range'] = $mobs['range'];
        }

        if (!empty($o['redstone'])) {
            $p['paper_world']['misc.redstone-implementation'] = 'ALTERNATE_CURRENT';
        }

        if (!empty($o['no_treasure_maps'])) {
            $p['paper_world']['environment.treasure-maps.enabled'] = false;
        }

        return $p;
    }

    /** @return array<string, string> clave => etiqueta */
    public static function labels(): array
    {
        return array_map(fn (array $p) => $p['label'], self::all());
    }

    /**
     * Archivos que toca cada perfil: ruta dentro del servidor => [clave del
     * perfil, tipo de edicion].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fileMap(): array
    {
        return [
            '/server.properties' => ['properties', 'properties'],
            '/spigot.yml' => ['spigot', 'yaml'],
            '/bukkit.yml' => ['bukkit', 'yaml'],
            '/config/paper-world-defaults.yml' => ['paper_world', 'yaml'],
        ];
    }

    /**
     * Banderas de la JVM (Aikar's flags, https://docs.papermc.io/paper/aikars-flags).
     * Con 12 GB o mas de heap se usa la variante grande.
     */
    public static function jvmFlags(int $memoryMb): string
    {
        $large = $memoryMb >= 12288;

        $flags = [
            '-XX:+UseG1GC',
            '-XX:+ParallelRefProcEnabled',
            '-XX:MaxGCPauseMillis=200',
            '-XX:+UnlockExperimentalVMOptions',
            '-XX:+DisableExplicitGC',
            '-XX:+AlwaysPreTouch',
            '-XX:G1NewSizePercent=' . ($large ? 40 : 30),
            '-XX:G1MaxNewSizePercent=' . ($large ? 50 : 40),
            '-XX:G1HeapRegionSize=' . ($large ? '16M' : '8M'),
            '-XX:G1ReservePercent=' . ($large ? 15 : 20),
            '-XX:G1HeapWastePercent=5',
            '-XX:G1MixedGCCountTarget=4',
            '-XX:InitiatingHeapOccupancyPercent=' . ($large ? 20 : 15),
            '-XX:G1MixedGCLiveThresholdPercent=90',
            '-XX:G1RSetUpdatingPauseTimePercent=5',
            '-XX:SurvivorRatio=32',
            '-XX:+PerfDisableSharedMem',
            '-XX:MaxTenuringThreshold=1',
            '-Dusing.aikars.flags=https://mcflags.emc.gs',
            '-Daikars.new.flags=true',
        ];

        return implode(' ', $flags);
    }
}
