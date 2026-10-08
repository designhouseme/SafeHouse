<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('SHOUSE_HOST_PROTECTION_LAB') !== '1') {
    fwrite(STDERR, "FAIL: this runner is only for the disposable host-protection container.\n");
    exit(2);
}

const STATE = '/tmp/shouse-host-protection';
const FPM_LOG = '/tmp/shouse-fpm-error.log';
mkdir(STATE, 0700);
$token = bin2hex(random_bytes(16));
file_put_contents(STATE . '/token', $token);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Compare kernel process start times as well as PIDs, so PID reuse cannot satisfy an assertion. */
function identity(int $pid): ?string
{
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if (!is_string($stat)) {
        return null;
    }
    $fields = preg_split('/\s+/', substr($stat, (int) strrpos($stat, ')') + 2));
    return ($fields[0] ?? '') === 'Z' ? null : ($fields[19] ?? null);
}

function wait_for(callable $predicate, float $seconds, string $message): void
{
    $deadline = hrtime(true) / 1e9 + $seconds;
    do {
        if ($predicate()) {
            return;
        }
        usleep(25000);
    } while (hrtime(true) / 1e9 < $deadline);
    throw new RuntimeException($message);
}

/** The trailing newline proves the complete marker was written, not just the file created. */
function wait_pid(string $path, string $message): int
{
    $pid = 0;
    wait_for(static function () use ($path, &$pid): bool {
        $marker = @file_get_contents($path);
        if (!is_string($marker) || !preg_match('/^([1-9][0-9]*)\n$/D', $marker, $match)) {
            return false;
        }
        $pid = (int) $match[1];
        return $pid > 1;
    }, 2, $message);
    return $pid;
}

/** Start a request without waiting for its response, so the PID can be observed while running. */
function request(string $mode): array
{
    global $token;
    $id = bin2hex(random_bytes(6));
    $socket = stream_socket_client('tcp://nginx:8080', $errno, $error, 2);
    check(is_resource($socket), 'Cannot connect to the private nginx fixture: ' . $error);
    stream_set_timeout($socket, 16);
    fwrite($socket, "GET /probe.php?mode=$mode&id=$id HTTP/1.0\r\nHost: lab\r\nX-Shouse-Lab-Token: $token\r\nConnection: close\r\n\r\n");
    return [$socket, $id];
}

function response($socket): string
{
    $response = stream_get_contents($socket);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);
    check(is_string($response) && !$metadata['timed_out'], 'HTTP client deadline reached.');
    return $response;
}

function healthy(): void
{
    [$socket] = request('healthy');
    $response = response($socket);
    check(str_starts_with($response, 'HTTP/1.1 200') || str_starts_with($response, 'HTTP/1.0 200'), 'Healthy request failed.');
    $body = json_decode(explode("\r\n\r\n", $response, 2)[1] ?? '', true);
    check(($body['ok'] ?? false) === true && ($body['sapi'] ?? '') === 'fpm-fcgi', 'Request did not run successfully through FPM.');
}

function cli_job(string $mode): array
{
    $command = ['/usr/bin/flock', '--nonblock', STATE . '/cron.lock', '/usr/bin/timeout', '--signal=TERM', '--kill-after=1s', '2s', PHP_BINARY, '/fixtures/cron.php', $mode];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    check(is_resource($process), 'Cannot start isolated cron fixture.');
    return [$process];
}

try {
    printf("INFO: PHP %s FPM; test-only 3s host deadline, 24M PHP memory limit, 2 workers\n", PHP_VERSION);
    wait_for(static function (): bool {
        $socket = @stream_socket_client('tcp://nginx:8080', $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return true;
        }
        return false;
    }, 5, 'Private nginx did not become ready.');
    healthy();

    foreach (['cpu', 'blocked', 'finished', 'shutdown'] as $mode) {
        $started = hrtime(true) / 1e9;
        [$socket, $id] = request($mode);
        $pid = wait_pid(STATE . '/' . $id . '.started', "$mode did not start.");
        $before = identity($pid);
        check($before !== null, "$mode worker disappeared before the test observed it.");
        if ($mode === 'finished') {
            $reply = response($socket);
            check(str_contains($reply, 'response-finished'), 'fastcgi_finish_request did not finish the response.');
            check(wait_pid(STATE . '/' . $id . '.after-response', 'Post-response loop did not start.') === $pid, 'Unexpected post-response PID.');
            check(identity($pid) === $before, 'Post-response worker was not alive before its host deadline.');
        } elseif ($mode === 'shutdown') {
            check(wait_pid(STATE . '/' . $id . '.in-shutdown', 'Shutdown loop did not start.') === $pid, 'Unexpected shutdown PID.');
        }
        wait_for(static fn(): bool => identity($pid) !== $before, 10, "$mode worker survived the FPM deadline.");
        $elapsed = hrtime(true) / 1e9 - $started;
        check($elapsed >= 2.5 && $elapsed < 10, "$mode did not terminate near the test-only host deadline.");
        $log = (string) file_get_contents(FPM_LOG);
        check((bool) preg_match('/child ' . $pid . ', script [^\r\n]* execution timed out [^\r\n]* terminating/', $log), "$mode has no FPM termination evidence for PID $pid.");
        if ($mode !== 'finished') {
            response($socket); // The response status alone is deliberately not the success criterion.
        }
        healthy();
        printf("PASS: %-8s PID %d ended through FPM in %.2fs; subsequent request healthy\n", $mode, $pid, $elapsed);
    }

    [$socket] = request('memory');
    $reply = response($socket);
    check((bool) preg_match('/^HTTP\/1\.[01] 500\b/', $reply), 'PHP memory exhaustion did not return HTTP 500.');
    check(str_contains((string) file_get_contents(FPM_LOG), 'Allowed memory size'), 'Missing actual PHP memory exhaustion evidence.');
    healthy();
    echo "PASS: actual PHP memory exhaustion; subsequent request healthy\n";

    check(is_executable('/usr/bin/flock') && is_executable('/usr/bin/timeout'), 'Debian flock and GNU timeout are required.');
    [$first] = cli_job('blocked');
    $entry = '';
    $cron_pid = 0;
    wait_for(static function () use (&$entry, &$cron_pid): bool {
        $ledger = @file_get_contents(STATE . '/cron-entries');
        if (!is_string($ledger) || !preg_match('/^blocked:([1-9][0-9]*)\n$/D', $ledger, $match)) {
            return false;
        }
        $entry = trim($ledger);
        $cron_pid = (int) $match[1];
        return $cron_pid > 1;
    }, 1, 'First cron invocation did not acquire the lock.');
    $cron_before = identity($cron_pid);
    check($cron_before !== null, 'Cron fixture did not remain alive.');
    [$second] = cli_job('healthy');
    check(proc_close($second) === 1, 'Second concurrent cron invocation was not rejected by flock.');
    check(trim((string) file_get_contents(STATE . '/cron-entries')) === $entry, 'Second cron invocation entered the protected job.');
    check(proc_close($first) === 124, 'Blocked cron was not ended by GNU timeout.');
    wait_for(static fn(): bool => identity($cron_pid) !== $cron_before, 1, 'Cron PHP process survived timeout.');
    [$third] = cli_job('healthy');
    check(proc_close($third) === 0, 'Cron lock was not released after timeout.');
    check(count(file(STATE . '/cron-entries', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === 2, 'Unexpected number of executed cron jobs.');
    echo "PASS: concurrent CLI cron rejected; blocked PID ended by timeout; lock reusable\n";
    echo "PASS: disposable host protection checks complete (test-only limits)\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
