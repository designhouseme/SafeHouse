<?php
// A harmless stand-in for a CLI cron job. It never boots WordPress or contacts another service.
declare(strict_types=1);

$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || getenv('SHOUSE_HOST_PROTECTION_LAB') !== '1'
    || !in_array($mode, ['blocked', 'healthy'], true)
    || !is_file('/tmp/shouse-host-protection/token')) {
    exit(2);
}
file_put_contents('/tmp/shouse-host-protection/cron-entries', $mode . ':' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
if ($mode === 'blocked') {
    set_time_limit(0);
    while (true) {
        usleep(100000);
    }
}
