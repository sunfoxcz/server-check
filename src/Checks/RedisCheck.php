<?php

namespace Sunfox\ServerCheck\Checks;

use Predis\Client;
use RuntimeException;

final class RedisCheck implements Check
{
    private $host;
    private $port;

    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function run()
    {
        $redis = new Client(['host' => $this->host, 'port' => $this->port]);
        try {
            if ((string) $redis->ping() !== 'PONG') {
                throw new RuntimeException('Redis ping failed');
            }
        } finally {
            $redis->disconnect();
        }
    }
}
