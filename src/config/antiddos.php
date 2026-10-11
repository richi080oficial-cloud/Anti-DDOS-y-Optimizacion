<?php

return [
    'defaults' => [
        'enabled' => true,

        'general_limit' => 480,

        'user_limit' => 1500,

        'auth_limit' => 15,

        'ban_minutes' => 30,

        'ban_max_minutes' => 1440,

        'whitelist' => '',

        'log_events' => true,

        'retention_days' => 14,

        'fw_tcp_ports' => '',
        'fw_udp_ports' => '',
        'fw_conn_limit' => '',
        'fw_syn_rate' => '',
        'fw_udp_rate' => '',
        'fw_ban_seconds' => '',
        'fw_attack_mode' => '',

        'fw_auto' => true,
        'fw_extra_ports' => true,
        'fw_egg_ids' => '',

        'fw_ban_mode' => '',

        'intel_enabled' => true,

        'auto_block_malicious' => false,
        'auto_block_duration' => '24h',
    ],

    'exempt_paths' => [
        'api/remote/*',
        'api/antiddos/*',
        'assets/*',
        'extensions/*',
        'favicons/*',
        'favicon*',
        'robots.txt',
    ],

    'auth_paths' => [
        'auth/login',
        'auth/login/checkpoint',
        'auth/password',
        'auth/password/reset',
    ],
];
