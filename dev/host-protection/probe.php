<?php
// Intentionally hostile requests, available only inside the disposable internal-network lab.
declare(strict_types=1);

$directory = '/tmp/shouse-host-protection';
$token = @file_get_contents($directory . '/token');
$mode = $_GET['mode'] ?? '';
$id = $_GET['id'] ?? '';
if (getenv('SHOUSE_HOST_PROTECTION_LAB') !== '1'
    || !is_string($token) || strlen($token) !== 32
    || !hash_equals($token, (string) ($_SERVER['HTTP_X_SHOUSE_LAB_TOKEN'] ?? ''))
    || !in_array($mode, ['healthy', 'cpu', 'blocked', 'finished', 'shutdown', 'memory'], true)
    || !is_string($id) || !preg_match('/^[a-f0-9]{12}$/D', $id)
) {
    http_response_code(404);
    exit;
}

function mark(string $directory, string $id, string $stage): void
{
    file_put_contents($directory . '/' . $id . '.' . $stage, getmypid() . "\n", LOCK_EX);
}

function spin(): never
{
    set_time_limit(0);
    while (true) {
        hash('sha256', 'disposable-host-protection-fixture');
    }
}

mark($directory, $id, 'started');
header('Content-Type: application/json');
if ($mode === 'healthy') {
    echo json_encode(['ok' => true, 'pid' => getmypid(), 'sapi' => PHP_SAPI]);
    exit;
}
if ($mode === 'cpu') {
    spin();
}
if ($mode === 'blocked') {
    set_time_limit(0);
    sleep(30);
    throw new RuntimeException('The host failed to stop the blocking request.');
}
if ($mode === 'finished') {
    echo json_encode(['ok' => true, 'pid' => getmypid(), 'stage' => 'response-finished']);
    fastcgi_finish_request();
    mark($directory, $id, 'after-response');
    spin();
}
if ($mode === 'shutdown') {
    register_shutdown_function(static function () use ($directory, $id): void {
        mark($directory, $id, 'in-shutdown');
        spin();
    });
    echo json_encode(['ok' => true, 'pid' => getmypid()]);
    exit;
}
if ($mode === 'memory') {
    $blocks = [];
    while (true) {
        $blocks[] = str_repeat('m', 1024 * 1024);
    }
}
