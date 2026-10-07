<?php
/** Regression tests against an isolated real Redis. Run object-cache-security.sh. */
declare(strict_types=1);

define('ABSPATH', '/audit-wordpress/');
define('WP_REDIS_HOST', getenv('SHOUSE_TEST_REDIS') ?: 'redis');
define('DB_HOST', getenv('SHOUSE_TEST_DB_HOST') ?: 'db-a');
define('DB_NAME', 'wordpress');
$mode = $argv[1] ?? 'cache';
if ('secret-missing' !== $mode) {
    define('AUTH_KEY', 'secret-default' === $mode ? 'put your unique phrase here' : ('secret-empty' === $mode ? '' : '7f84d06935d329f7d654b0a7f5dd963ba54d05e64690532782d3d1f43f35a40b'));
}
$GLOBALS['table_prefix'] = 'wp_';

function is_multisite(): bool { return true; }
function get_current_blog_id(): int { return 1; }
function check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}

/** Only the durable guard database is simulated; every Redis command uses the server. */
final class GuardDatabase {
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public array $rows = [];
    public bool $replace_marker_before_delete = false;
    public bool $fail_reads = false;
    public function suppress_errors(bool $value): bool { return false; }
    public function prepare(string $sql, mixed ...$args): string { return json_encode([$sql, $args], JSON_THROW_ON_ERROR); }
    public function get_var(string $prepared): ?string {
        [, $args] = json_decode($prepared, true, 512, JSON_THROW_ON_ERROR);
        $this->last_error = $this->fail_reads ? 'Simulated database read failure' : '';
        return $this->fail_reads ? null : ($this->rows[$args[0]] ?? null);
    }
    public function query(string $prepared): int {
        [$sql, $args] = json_decode($prepared, true, 512, JSON_THROW_ON_ERROR);
        if (str_starts_with($sql, 'DELETE')) {
            if ($this->replace_marker_before_delete) { $this->rows[$args[0]] = str_repeat('b', 32); }
            if (($this->rows[$args[0]] ?? null) !== ($args[1] ?? null)) { return 0; }
            unset($this->rows[$args[0]]);
            return 1;
        }
        if (str_starts_with($sql, 'INSERT IGNORE')) {
            $this->rows[$args[0]] ??= $args[1];
        } else {
            for ($i=0; $i<count($args); $i+=2) { $this->rows[$args[$i]] = $args[$i+1]; }
        }
        return 1;
    }
}

$GLOBALS['wpdb'] = new GuardDatabase();
require __DIR__.'/../../plugin/src/ObjectCache/connect.php';
if (str_starts_with($mode, 'secret-')) {
    require __DIR__.'/../../plugin/src/ObjectCache/object-cache.php';
    check(!function_exists('wp_cache_init') && !class_exists('WP_Object_Cache', false), $mode.' leaves the native cache available');
    check(str_contains($GLOBALS['shouse_object_cache_error'] ?? '', 'non-default WordPress authentication key'), $mode.' reports the configuration problem');
    exit;
}
if (($argv[1] ?? '') === 'prefix') { echo shouse_object_cache_prefix(), "\n"; exit; }

function connection(?string $user = null): Redis {
    $r = new Redis();
    $r->connect(WP_REDIS_HOST, 6379, 1.0);
    if (null !== $user) { $r->auth([$user, 'test-only-password']); }
    return $r;
}
function restricted(Redis $admin, string $name, string $deny): Redis {
    $admin->rawCommand('ACL', 'SETUSER', $name, 'reset', 'on', '>test-only-password', '+@all', '-'.$deny, '~*');
    return connection($name);
}

$r = connection();
$mode = $argv[1] ?? 'cache';
if (str_starts_with($mode, 'guard-')) {
    $GLOBALS['wpdb']->rows = [
        'shouse_object_cache_stale'=>str_repeat('a', 32),
        'shouse_object_cache_generation'=>str_repeat('a', 32),
    ];
    if (in_array($mode, ['guard-scan', 'guard-unlink'], true)) {
        $deny = substr($mode, 6);
        restricted($r, 'guard-'.$deny, $deny)->close();
        define('WP_REDIS_PASSWORD', ['guard-'.$deny, 'test-only-password']);
        $r->set(shouse_object_cache_prefix().'retained-data', 'must-not-be-read');
    }
    if ('guard-race' === $mode) { $GLOBALS['wpdb']->replace_marker_before_delete = true; }
    if ('guard-db' === $mode) { $GLOBALS['wpdb']->fail_reads = true; }
    require __DIR__.'/../../plugin/src/ObjectCache/object-cache.php';
    if ('guard-success' === $mode) {
        check(function_exists('wp_cache_init'), 'successful recovery loads the external cache');
        check(!isset($GLOBALS['wpdb']->rows['shouse_object_cache_stale']), 'successful recovery removes its own marker');
    } else {
        check(!function_exists('wp_cache_init'), $mode.' leaves WordPress free to load its native cache');
        check(!class_exists('WP_Object_Cache', false), $mode.' does not reserve the native cache class');
        check(isset($GLOBALS['wpdb']->rows['shouse_object_cache_stale']), $mode.' retains a recovery marker');
    }
    exit;
}

require __DIR__.'/../../plugin/src/ObjectCache/Cache.php';
use SafeHouse\ObjectCache\Cache;

$prefix = 'regression:'.bin2hex(random_bytes(8)).':';
$secret = 'test-only-cache-secret';
$GLOBALS['shouse_object_cache_generation'] = str_repeat('a', 32);
$a = new Cache($r, $prefix, $secret);
$a->set('item', 'site-a', 'posts');
$a->switch_to_blog(2);
check(false === $a->get('item', 'posts'), 'runtime does not leak blog A into blog B');
check($a->add('item', 'site-b', 'posts'), 'add in another blog has its own namespace');
$a->switch_to_blog(1);
check('site-a' === $a->get('item', 'posts'), 'returning to blog A preserves its own runtime value');
$a->flush_runtime();
check('site-a' === $a->get('item', 'posts'), 'blog A survives a Redis round-trip');
$a->switch_to_blog(2);
check(['item'=>'site-b'] === $a->get_multiple(['item'], 'posts'), 'get_multiple respects blog B');
$a->delete('item', 'posts');
$a->switch_to_blog(1);
check('site-a' === $a->get('item', 'posts', true), 'deleting in B preserves A in Redis');
$a->add_global_groups('shared');
$a->set('item', 'global', 'shared');
$a->switch_to_blog(2);
check('global' === $a->get('item', 'shared'), 'global groups are shared intentionally');
$a->add_non_persistent_groups('request');
$a->set('item', 'local-b', 'request');
$a->switch_to_blog(1);
check(false === $a->get('item', 'request'), 'non-persistent groups also respect blog scope');

$a->set('c', 'one', 'a:b');
$a->set('b:c', 'two', 'a');
$a->set('nested:a:b:c', 'three', 'another');
$a->flush_runtime();
check('one' === $a->get('c', 'a:b') && 'two' === $a->get('b:c', 'a'), 'group and key separators cannot collide');
$a->flush_group('a');
$a->flush_runtime();
check(false === $a->get('b:c', 'a') && 'one' === $a->get('c', 'a:b') && 'three' === $a->get('nested:a:b:c', 'another'), 'group flush has exact boundaries');

foreach (['users', 'user_meta', 'userlogins', 'useremail', 'userslugs', 'user-queries', 'options', 'site-options'] as $group) {
    $a->set('42', 'old privileged state', $group);
    check('old privileged state' === $a->get('42', $group), $group.' supports runtime caching');
    check([] === $r->keys($prefix.bin2hex($group).':*'), $group.' never writes authorization data to Redis');
    $key = $prefix.bin2hex($group).':'.$GLOBALS['shouse_object_cache_generation'].':1:'.bin2hex('42');
    $payload = serialize('replayed privileged state');
    $r->set($key, hash_hmac('sha256', $key."\0".$payload, $secret, true).$payload);
    $fresh = new Cache(connection(), $prefix, $secret);
    check(false === $fresh->get('42', $group), $group.' ignores even a correctly signed replay');
}

$a->set('counter', 0, 'counters', 30);
$b = new Cache(connection(), $prefix, $secret);
$a->get('counter', 'counters');
$b->get('counter', 'counters');
check(1 === $a->incr('counter', 1, 'counters') && 2 === $b->incr('counter', 1, 'counters'), 'increments ignore stale runtime copies');
$keys = $r->keys($prefix.bin2hex('counters').':*');
check(count($keys) === 1 && $r->ttl($keys[0]) > 0 && $r->ttl($keys[0]) <= 30, 'increment preserves the existing TTL');
check(0 === $b->decr('counter', 10, 'counters'), 'decrement is clamped at zero');

$children = [];
for ($worker=0; $worker<6; $worker++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $worker_cache = new Cache(connection(), $prefix, $secret);
        for ($j=0; $j<100; $j++) {
            if (false === $worker_cache->incr('counter', 1, 'counters')) { fwrite(STDERR, 'Counter failed: '.$worker_cache->last_error()."\n"); exit(1); }
        }
        exit(0);
    }
    check($pid > 0, 'counter worker spawned');
    $children[] = $pid;
}
foreach ($children as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'counter worker completed'); }
// Forked clients inherit file descriptors: reconnect the parent's connection before using it again.
$r = connection();
$fresh = new Cache($r, $prefix, $secret);
check(600 === $fresh->get('counter', 'counters'), '600 concurrent increments produce exactly 600');

$fresh->set('expired-counter', 4, 'expire', 1);
sleep(2);
check(false === $fresh->incr('expired-counter', 1, 'expire'), 'expired Redis value is not resurrected from runtime');

foreach (['set', 'del'] as $denied) {
    $failing = new Cache(restricted($r, 'deny-'.$denied, $denied), $prefix, $secret);
    if ('set' === $denied) {
        check($failing->set('outage', 'first-write', 'transient'), 'first failed Redis write succeeds in runtime');
    } else {
        $failing->delete('item', 'posts');
    }
    check(!$failing->redis_status(), $denied.' failure disconnects the persistent backend');
    check($failing->set('outage', 'second-write', 'transient'), 'transient write survives disconnected backend');
    check('second-write' === $failing->get('outage', 'transient'), 'transient remains readable during the request');
    check($failing->add('new-outage', 1) && 2 === $failing->incr('new-outage'), 'add and increment work after disconnection');
    check(['a'=>true,'b'=>true] === $failing->set_multiple(['a'=>1,'b'=>2]), 'batch writes work after disconnection');
    check(isset($GLOBALS['wpdb']->rows['shouse_object_cache_stale']), 'mid-request error records a durable recovery marker');
}

$old = new Cache(connection(), $prefix, $secret);
shouse_object_cache_mark_stale();
$GLOBALS['shouse_object_cache_generation'] = shouse_object_cache_generation();
$new = new Cache(connection(), $prefix, $secret);
$old->set('late-write', 'outdated');
check(false === $new->get('late-write'), 'pre-outage request cannot repopulate the recovered namespace');
$new->set('late-write', 'current');
check('current' === $new->get('late-write', '', true), 'new generation can persist current data');
check($new->flush(), 'flush succeeds on a healthy backend');
check([] === $r->keys($prefix.'*'), 'flush removes only the dedicated test namespace');
echo "All object cache regressions passed.\n";
