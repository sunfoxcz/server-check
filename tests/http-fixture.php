<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (substr($path, -5) === '/RPC2') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['CONTENT_TYPE'] !== 'text/xml') {
        http_response_code(400);
        return;
    }

    if ($path === '/authenticated/RPC2'
        && (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
            || $_SERVER['PHP_AUTH_USER'] !== 'server_check'
            || $_SERVER['PHP_AUTH_PW'] !== 'fixture:password')
    ) {
        header('WWW-Authenticate: Basic realm="Supervisor"');
        http_response_code(401);
        return;
    }

    header('Content-Type: text/xml');
    if ($path === '/invalid/RPC2') {
        echo '<methodResponse';
        return;
    }
    if ($path === '/fault/RPC2') {
        echo '<methodResponse><fault><value><struct/></value></fault></methodResponse>';
        return;
    }

    $request = file_get_contents('php://input');
    if (strpos($request, '<methodName>supervisor.getState</methodName>') !== false) {
        $state = $path === '/shutdown/RPC2' ? 'SHUTDOWN' : 'RUNNING';
        $result = supervisorStruct(['statename' => $state]);
    } elseif (strpos($request, '<methodName>supervisor.getAllProcessInfo</methodName>') !== false) {
        if ($path === '/invalid-process-list/RPC2') {
            $result = '<string>Unexpected process list</string>';
        } else {
            $processes = $path === '/empty/RPC2' ? [] : [
                ['group' => 'web', 'name' => 'server', 'statename' => 'RUNNING'],
            ];
            if ($path === '/unhealthy/RPC2') {
                $processes[] = ['group' => 'jobs', 'name' => 'worker', 'statename' => 'STOPPED'];
                $processes[] = ['group' => 'jobs', 'name' => 'scheduler', 'statename' => 'FATAL'];
            }

            $result = '<array><data>';
            foreach ($processes as $process) {
                $result .= '<value>' . supervisorStruct($process) . '</value>';
            }
            $result .= '</data></array>';
        }
    } else {
        http_response_code(400);
        return;
    }

    echo '<methodResponse><params><param><value>' . $result . '</value></param></params></methodResponse>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    return;
}

header('Content-Type: application/json; charset=utf-8');

if (strpos($path, '/json-api/') === 0) {
    switch ($path) {
        case '/json-api/query':
            if ($_SERVER['QUERY_STRING'] !== 'apiKey=test-key%20%26%20value&mode=full') {
                http_response_code(400);
                return;
            }
            break;
        case '/json-api/redirect':
            header('Location: /json-api/query?' . $_SERVER['QUERY_STRING'], true, 302);
            return;
        case '/json-api/array-error':
            http_response_code(503);
            echo '{"problems":["First problem","Second problem"],"unused":"Other field"}';
            return;
        case '/json-api/string-error':
            http_response_code(422);
            echo '{"message":"Service unavailable"}';
            return;
        case '/json-api/malformed-error':
            http_response_code(500);
            echo '{';
            return;
        case '/json-api/html':
            header('Content-Type: text/html');
            break;
        case '/json-api/slow':
            usleep(250000);
            break;
    }

    echo '{"status":"unhealthy","problems":["Ignored on HTTP 200"]}';
    return;
}

if (substr($path, -16) === '/_cluster/health') {
    if ($_SERVER['QUERY_STRING'] !== 'wait_for_status=yellow&timeout=2s') {
        http_response_code(400);
        return;
    }

    if ($path === '/unavailable/_cluster/health') {
        http_response_code(408);
    }
    if ($path === '/_cluster/health') {
        echo '{"status":"yellow","timed_out":false}';
    } else {
        echo '{"status":"red","timed_out":true}';
    }
    return;
}

http_response_code(404);

function supervisorStruct(array $fields)
{
    $xml = '<struct>';
    foreach ($fields as $name => $value) {
        $xml .= '<member><name>' . $name . '</name><value><string>' . $value . '</string></value></member>';
    }

    return $xml . '</struct>';
}
