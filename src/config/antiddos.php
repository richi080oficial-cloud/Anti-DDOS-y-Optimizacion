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

        // Registrar eventos (bloqueos, aplicaciones de optimizacion...).
        'log_events' => true,

        // Dias que se conservan los eventos.
        'retention_days' => 14,

        // Perfil de optimizacion preseleccionado en el admin.
        'default_profile' => 'balanced',

        // Porcentaje de la RAM del servidor que se asigna al heap de Java al
        // optimizar (el resto queda para metaspace, hilos y buffers nativos;
        // si se asigna el 100 % Docker mata el contenedor por falta de memoria).
        'heap_percent' => 90,
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
