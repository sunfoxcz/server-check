<?php

use Nette\Neon\Neon;
use Sunfox\ServerCheck\Checks\ElasticsearchCheck;
use Sunfox\ServerCheck\Checks\JsonApiCheck;
use Sunfox\ServerCheck\Checks\RabbitMqCheck;
use Sunfox\ServerCheck\Checks\RedisCheck;
use Sunfox\ServerCheck\Checks\SupervisorCheck;
use Sunfox\ServerCheck\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function expect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFailure(callable $check, $message)
{
    try {
        $check();
    } catch (Exception $error) {
        expect(strpos($error->getMessage(), $message) !== false, 'Unexpected exception: ' . $error->getMessage());
        return;
    }

    throw new RuntimeException('Expected failure: ' . $message);
}

function runMonitoring($entrypoint, $ip)
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    http_response_code(200);
    ob_start();
    try {
        require $entrypoint;
        return [http_response_code(), ob_get_contents()];
    } finally {
        ob_end_clean();
    }
}

$exampleConfig = Neon::decode(file_get_contents(dirname(__DIR__) . '/config/config.example.neon'));
expect(array_keys($exampleConfig['checks']) === ['redis', 'database', 'elasticsearch', 'rabbitmq', 'supervisor'], 'Default check configuration');

$configDirectory = tempnam(sys_get_temp_dir(), 'server-check');
unlink($configDirectory);
mkdir($configDirectory);
mkdir($configDirectory . '/public');
mkdir($configDirectory . '/config');
$entrypoint = $configDirectory . '/public/index.php';
$configFile = $configDirectory . '/config/config.neon';
$vendorLink = $configDirectory . '/vendor';
copy(dirname(__DIR__) . '/public/index.php', $entrypoint);
symlink(dirname(__DIR__) . '/vendor', $vendorLink);
try {
    $config = <<<'NEON'
adminIps:
    - '127.0.0.1'
checks:
    database:
        Database one:
            dsn: 'sqlite::memory:'
            user: check
            password: check
        Database two:
            dsn: 'sqlite::memory:'
            user: check
            password: check
NEON;
    file_put_contents($configFile, $config);
    expect(runMonitoring($entrypoint, '127.0.0.2') === [200, 'All checks complete'], 'Monitoring success');
    expect(
        runMonitoring($entrypoint, '127.0.0.1') === [200, 'Database one: OK<br>Database two: OK<br>All checks complete'],
        'Both configured check instances should run'
    );

    $config .= "\n" . <<<'NEON'
    supervisor:
        Local Supervisor:
            url: 'http://127.0.0.1:18080/RPC2'
            timeout: 2
        Authenticated Supervisor:
            url: 'http://127.0.0.1:18080/authenticated/RPC2'
            username: server_check
            password: 'fixture:password'
            timeout: 2
        Supervisor with stopped worker:
            url: 'http://127.0.0.1:18080/stopped/RPC2'
            ignoreStopped: true
            timeout: 2
    jsonApi:
        Failing JSON endpoint:
            url: 'http://127.0.0.1:18080/json-api/array-error'
            errorField: problems
            timeout: 2
        Healthy JSON endpoint:
            url: 'http://127.0.0.1:18080/json-api/query?apiKey=test-key%20%26%20value&mode=full'
            timeout: 2
NEON;
    file_put_contents($configFile, $config);
    expect(runMonitoring($entrypoint, '127.0.0.2') === [500, ''], 'Monitoring failure');
    list($status, $output) = runMonitoring($entrypoint, '127.0.0.1');
    expect($status === 200, 'Admin response status');
    expect(strpos($output, 'Failing JSON endpoint error: status=503') !== false, 'JSON HTTP error status');
    expect(strpos($output, 'First problem<br') !== false && strpos($output, 'Second problem') !== false, 'Configured JSON error details');
    expect(strpos($output, 'Other field') === false, 'Report only the configured JSON error field');
    expect(strpos($output, 'Healthy JSON endpoint: OK') !== false, 'JSON API with query parameters');
    expect(strpos($output, 'Local Supervisor: OK') !== false, 'Supervisor without credentials');
    expect(strpos($output, 'Authenticated Supervisor: OK') !== false, 'Supervisor HTTP Basic credentials');
    expect(strpos($output, 'Supervisor with stopped worker: OK') !== false, 'Supervisor ignores stopped processes when configured');
    expect(strpos($output, 'All checks complete') !== false, 'Admin should see the final status');

    $configLog = $configDirectory . '/php-error.log';
    $previousErrorLog = ini_set('error_log', $configLog);
    try {
        file_put_contents($configFile, '{invalid: ]}');
        expect(runMonitoring($entrypoint, '127.0.0.1') === [500, ''], 'Invalid NEON should return HTTP 500');
        expect(strpos(file_get_contents($configLog), 'Invalid NEON syntax') !== false, 'Log the invalid configuration');
    } finally {
        ini_set('error_log', $previousErrorLog);
        if (is_file($configLog)) {
            unlink($configLog);
        }
    }
} finally {
    unlink($configFile);
    unlink($entrypoint);
    unlink($vendorLink);
    rmdir($configDirectory . '/config');
    rmdir($configDirectory . '/public');
    rmdir($configDirectory);
}

$redis = new RedisCheck('127.0.0.1', 1);
expectFailure([$redis, 'run'], 'Connection refused');
$rabbit = new RabbitMqCheck([
    'host' => '127.0.0.1', 'port' => 1, 'vhost' => 'check', 'user' => 'check', 'password' => 'fixture',
]);
expectFailure([$rabbit, 'run'], 'Connection refused');

// Use real HTTP requests to a fixture on the container loopback interface.
$http = new HttpClient();
$fixtureUrl = 'http://127.0.0.1:18080';
$query = '?apiKey=test-key%20%26%20value&mode=full';
$healthy = new JsonApiCheck($http, $fixtureUrl . '/json-api/ok', 2, 'problems');
$healthy->run();
$redirect = new JsonApiCheck($http, $fixtureUrl . '/json-api/redirect' . $query, 2);
$redirect->run();

$jsonFailures = [
    ['array-error', 'problems', "status=503, content-type=application/json; charset=utf-8\nFirst problem\nSecond problem"],
    ['array-error', 'missingField', 'status=503, content-type=application/json; charset=utf-8'],
    ['string-error', 'message', "status=422, content-type=application/json; charset=utf-8\nService unavailable"],
    ['malformed-error', 'message', 'status=500, content-type=application/json; charset=utf-8'],
    ['html', null, 'status=200, content-type=text/html'],
];
foreach ($jsonFailures as $test) {
    list($path, $errorField, $message) = $test;
    $check = new JsonApiCheck($http, $fixtureUrl . '/json-api/' . $path, 2, $errorField);
    expectFailure([$check, 'run'], $message);
}

$unreachableUrl = 'http://127.0.0.1:1';
$unreachable = new JsonApiCheck($http, $unreachableUrl . '/status' . $query, 2);
try {
    $unreachable->run();
    throw new LogicException('Expected a connection failure');
} catch (RuntimeException $error) {
    expect(strpos($error->getMessage(), 'HTTP request failed') === 0, 'JSON API connection failure');
    expect(strpos($error->getMessage(), 'test-key') === false, 'Do not include URL credentials in transport errors');
}

$rpcUrl = $fixtureUrl . '/RPC2';
$unauthorized = new SupervisorCheck($http, $fixtureUrl . '/authenticated/RPC2', 2, 'server_check', 'wrong-password');
expectFailure([$unauthorized, 'run'], 'HTTP 401');
$incompleteAuth = new SupervisorCheck($http, $rpcUrl, 2, 'server_check');
expectFailure([$incompleteAuth, 'run'], 'requires username and password');
$empty = new SupervisorCheck($http, $fixtureUrl . '/empty/RPC2', 2);
$empty->run();
$rpcFailures = [
    'shutdown' => 'Supervisor state is SHUTDOWN',
    'invalid' => 'Invalid Supervisor XML-RPC response',
    'fault' => 'Supervisor XML-RPC returned a fault',
    'invalid-process-list' => 'Invalid Supervisor process list',
    'stopped' => 'jobs:worker is STOPPED',
    'unhealthy' => "jobs:worker is STOPPED\njobs:scheduler is FATAL",
];
foreach ($rpcFailures as $path => $message) {
    $check = new SupervisorCheck($http, $fixtureUrl . '/' . $path . '/RPC2', 2);
    expectFailure([$check, 'run'], $message);
}

$ignoreStopped = new SupervisorCheck($http, $fixtureUrl . '/unhealthy/RPC2', 2, null, null, true);
try {
    $ignoreStopped->run();
    throw new LogicException('Expected a Supervisor process failure');
} catch (RuntimeException $error) {
    expect($error->getMessage() === 'jobs:scheduler is FATAL', 'Ignore stopped processes while reporting other failures');
}

$healthOptions = ['wait_for_status' => 'yellow', 'timeout' => '2s'];
$elastic = new ElasticsearchCheck($http, [$unreachableUrl, $fixtureUrl], $healthOptions, 2);
$elastic->run();
$unavailable = new ElasticsearchCheck($http, [$fixtureUrl . '/unavailable'], $healthOptions, 2);
expectFailure([$unavailable, 'run'], 'HTTP 408');
$timedOut = new ElasticsearchCheck($http, [$fixtureUrl . '/timed-out'], $healthOptions, 2);
expectFailure([$timedOut, 'run'], 'health timed out');

$slow = new JsonApiCheck($http, $fixtureUrl . '/json-api/slow', 0.05);
expectFailure([$slow, 'run'], 'cURL 28');

echo "Smoke tests passed\n";
