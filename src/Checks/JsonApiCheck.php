<?php

namespace Sunfox\ServerCheck\Checks;

use RuntimeException;
use Sunfox\ServerCheck\HttpClient;

final class JsonApiCheck implements Check
{
    private $http;
    private $url;
    private $timeout;
    private $errorField;

    public function __construct(HttpClient $http, $url, $timeout, $errorField = null)
    {
        $this->http = $http;
        $this->url = $url;
        $this->timeout = $timeout;
        $this->errorField = $errorField;
    }

    public function run()
    {
        $response = $this->http->get($this->url, [], $this->timeout);
        $status = $response['status'];
        $contentType = $response['contentType'];
        $mediaType = trim(explode(';', $contentType, 2)[0]);
        $isJson = strcasecmp($mediaType, 'application/json') === 0;
        if ($status === 200 && $isJson) {
            return;
        }

        $message = "status=$status, content-type=$contentType";
        if ($isJson && is_string($this->errorField) && $this->errorField !== '') {
            $details = $this->readErrorDetails($response['body']);
            if ($details !== '') {
                $message .= "\n" . $details;
            }
        }

        throw new RuntimeException($message);
    }

    private function readErrorDetails($body)
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded[$this->errorField])) {
            return '';
        }

        $errors = $decoded[$this->errorField];
        if (is_string($errors)) {
            return trim($errors);
        }

        return is_array($errors) ? trim(implode("\n", array_filter($errors, 'is_string'))) : '';
    }
}
