<?php

namespace Sunfox\ServerCheck\Checks;

use RuntimeException;
use Sunfox\ServerCheck\HttpClient;

final class ElasticsearchCheck implements Check
{
    private $http;
    private $hosts;
    private $healthOptions;
    private $requestTimeout;

    public function __construct(HttpClient $http, array $hosts, array $healthOptions, $requestTimeout)
    {
        $this->http = $http;
        $this->hosts = $hosts;
        $this->healthOptions = $healthOptions;
        $this->requestTimeout = $requestTimeout;
    }

    public function run()
    {
        $lastError = new RuntimeException('No Elasticsearch hosts configured');

        foreach ($this->hosts as $host) {
            $url = rtrim($host, '/') . '/_cluster/health';
            try {
                $response = $this->http->get($url, $this->healthOptions, $this->requestTimeout);
            } catch (RuntimeException $error) {
                $lastError = $error;
                continue;
            }

            $status = $response['status'];
            if ($status !== 200) {
                throw new RuntimeException("Cluster health returned HTTP $status");
            }

            $body = json_decode($response['body'], true);
            if (!in_array(isset($body['status']) ? $body['status'] : null, ['green', 'yellow', 'red'], true)) {
                throw new RuntimeException('Invalid Elasticsearch cluster health response');
            }
            if (!empty($body['timed_out'])) {
                throw new RuntimeException('Elasticsearch cluster health timed out');
            }

            return;
        }

        throw $lastError;
    }
}
