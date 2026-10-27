<?php

return [
    // Must be provisioned independently of APP_KEY in a secret manager.
    'backup_key' => env('EXPO_BACKUP_KEY_BASE64'),
    'backup_directory' => env('EXPO_BACKUP_DIR', '/var/backups/boosted-export-erp'),
    'backup_max_age_hours' => (int) env('EXPO_BACKUP_MAX_AGE_HOURS', 26),
    'offsite_enabled' => env('EXPO_BACKUP_OFFSITE_ENABLED', false),
    'offsite_prefix' => env('EXPO_BACKUP_OFFSITE_PREFIX', 'encrypted-backups'),
    'backup_schedule' => env('EXPO_BACKUP_SCHEDULE_ENABLED', false),
    // Configure a scheduler and queue worker on a single production cluster.
    'monitor_schedule' => env('EXPO_MONITOR_SCHEDULE_ENABLED', false),
    'billing_schedule' => env('EXPO_BILLING_SCHEDULE_ENABLED', false),
    'alert_webhook' => env('EXPO_OPS_ALERT_WEBHOOK_URL'),
    'trusted_proxies' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'min_free_disk_gb' => (float) env('EXPO_MIN_FREE_DISK_GB', 2),
    'postgres_dump_binary' => env('EXPO_PG_DUMP', 'pg_dump'),
];
