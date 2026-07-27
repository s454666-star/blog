<?php

return [
    'cli_path' => env('AWS_LIGHTSAIL_METRICS_CLI_PATH', 'aws'),
    'profile' => env('AWS_LIGHTSAIL_METRICS_PROFILE', 'lightsail-metrics'),
    'credentials_file' => env('AWS_LIGHTSAIL_METRICS_CREDENTIALS_FILE', '/etc/blog/aws-lightsail-metrics-credentials'),
    'config_file' => env('AWS_LIGHTSAIL_METRICS_CONFIG_FILE', '/etc/blog/aws-lightsail-metrics-config'),
    'region' => env('AWS_LIGHTSAIL_METRICS_REGION', 'ap-northeast-1'),
    'instance' => env('AWS_LIGHTSAIL_METRICS_INSTANCE', 'star-s'),
    'daily_report_enabled' => env('AWS_LIGHTSAIL_NETWORK_REPORT_ENABLED', false),
    'daily_report_at' => env('AWS_LIGHTSAIL_NETWORK_REPORT_AT', '09:00'),
];
