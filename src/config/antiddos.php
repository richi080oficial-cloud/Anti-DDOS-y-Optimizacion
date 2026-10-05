<?php

/*
 * Valores por defecto de Anti-DDoS y Optimización.
 *
 * Todos pueden sobreescribirse desde el panel de administracion
 * (/admin/extensions/antiddos). Lo guardado en la tabla antiddos_settings
 * tiene prioridad; este archivo solo define el fallback.
 */

return [
    'defaults' => [
        // Interruptor general del escudo web (capa 7) del panel.
        'enabled' => true,

        // Peticiones por minuto y por IP antes de bloquear. No cuentan los
        // assets estaticos ni la API interna de Wings (api/remote/*).
        'general_limit' => 480,

        // Intentos por minuto y por IP contra las rutas de login/contrasena.
        'auth_limit' => 15,

        // Duracion del bloqueo automatico (minutos). Cada reincidencia la duplica.
        'ban_minutes' => 30,

        // Tope de la duracion cuando se duplica por reincidencia (minutos).
        'ban_max_minutes' => 1440,

        // Lista blanca: una IP o CIDR por linea. Nunca se bloquean.
        'whitelist' => '',

        // Registrar eventos (bloqueos, cambios de configuracion...).
        'log_events' => true,

        // Dias que se conservan los eventos.
        'retention_days' => 14,

        // Firewall de los nodos, gestionado desde el panel. Vacio = "no gestionado":
        // el nodo conserva lo que tenga en su agent.conf. Al guardar el formulario
        // de proteccion se rellenan y se envian a todos los nodos (feed, lineas C).
        'fw_tcp_ports' => '',
        'fw_udp_ports' => '',     // '-' = ninguno
        'fw_conn_limit' => '',
        'fw_syn_rate' => '',
        'fw_udp_rate' => '',
        'fw_ban_seconds' => '',
        'fw_attack_mode' => '',   // '1' = modo bajo ataque, '0' = normal

        // Deteccion automatica de puertos a partir de los servidores de Minecraft
        // (solo actua cuando el firewall esta gestionado desde el panel).
        'fw_auto' => true,          // proteger los puertos de los servidores de Minecraft
        'fw_extra_ports' => true,   // ...incluidas sus asignaciones adicionales (Dynmap, voice chat...)
        'fw_egg_ids' => '',         // eggs que cuentan como Minecraft (vacio = deteccion por nombre)
    ],

    // Rutas que NUNCA se cuentan ni se bloquean (patrones de Request::is()).
    'exempt_paths' => [
        'api/remote/*',          // Wings -> panel
        'api/antiddos/*',      // feed para los nodos (tiene su propia clave)
        'assets/*',
        'extensions/*',
        'favicons/*',
        'favicon*',
        'robots.txt',
    ],

    // Rutas de autenticacion con limite propio (solo metodos POST).
    'auth_paths' => [
        'auth/login',
        'auth/login/checkpoint',
        'auth/password',
        'auth/password/reset',
    ],
];
