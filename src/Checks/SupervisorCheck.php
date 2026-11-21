<?php

namespace Sunfox\ServerCheck\Checks;

use RuntimeException;
use Sunfox\ServerCheck\HttpClient;

final class SupervisorCheck implements Check
{
    private $http;
    private $url;
    private $timeout;
    private $username;
    private $password;
    private $ignoreStopped;

    public function __construct(HttpClient $http, $url, $timeout, $username = null, $password = null, $ignoreStopped = false)
    {
        $this->http = $http;
        $this->url = $url;
        $this->timeout = $timeout;
        $this->username = $username;
        $this->password = $password;
        $this->ignoreStopped = $ignoreStopped === true;
    }

    public function run()
    {
        if (($this->username !== null || $this->password !== null)
            && (!is_string($this->username) || $this->username === '' || !is_string($this->password))
        ) {
            throw new RuntimeException('Supervisor Basic authentication requires username and password');
        }

        $daemon = $this->readFields($this->call('supervisor.getState'), ['statename']);
        if ($daemon['statename'] !== 'RUNNING') {
            throw new RuntimeException('Supervisor state is ' . $daemon['statename']);
        }

        $processes = $this->call('supervisor.getAllProcessInfo');
        if (!isset($processes->array->data)) {
            throw new RuntimeException('Invalid Supervisor process list');
        }

        $failures = [];
        foreach ($processes->array->data->children() as $value) {
            if ($value->getName() !== 'value') {
                throw new RuntimeException('Invalid Supervisor process list');
            }

            $process = $this->readFields($value, ['group', 'name', 'statename']);
            $state = $process['statename'];
            if ($state === 'RUNNING' || ($this->ignoreStopped && $state === 'STOPPED')) {
                continue;
            }

            $failures[] = sprintf('%s:%s is %s', $process['group'], $process['name'], $state);
        }

        if ($failures !== []) {
            throw new RuntimeException(implode("\n", $failures));
        }
    }

    private function call($method)
    {
        $request = '<?xml version="1.0"?>'
            . '<methodCall><methodName>' . $method . '</methodName><params/></methodCall>';
        $response = $this->http->postXml($this->url, $request, $this->timeout, $this->username, $this->password);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Supervisor XML-RPC returned HTTP ' . $response['status']);
        }

        $xml = simplexml_load_string(
            $response['body'],
            'SimpleXMLElement',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        if ($xml === false || $xml->getName() !== 'methodResponse') {
            throw new RuntimeException('Invalid Supervisor XML-RPC response');
        }
        if (isset($xml->fault)) {
            throw new RuntimeException('Supervisor XML-RPC returned a fault');
        }

        $values = $xml->xpath('/methodResponse/params/param/value');
        if (!isset($values[0])) {
            throw new RuntimeException('Supervisor XML-RPC response is missing its return value');
        }

        return $values[0];
    }

    private function readFields($value, array $required)
    {
        if (!isset($value->struct)) {
            throw new RuntimeException('Invalid Supervisor XML-RPC structure');
        }

        $fields = [];
        foreach ($value->struct->member as $member) {
            $name = (string) $member->name;
            if (in_array($name, $required, true) && isset($member->value)) {
                $fields[$name] = (string) (isset($member->value->string) ? $member->value->string : $member->value);
            }
        }
        foreach ($required as $name) {
            if (!isset($fields[$name]) || $fields[$name] === '') {
                throw new RuntimeException("Supervisor XML-RPC response is missing $name");
            }
        }

        return $fields;
    }
}
