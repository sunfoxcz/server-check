<?php

namespace Sunfox\ServerCheck\Checks;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class RabbitMqCheck implements Check
{
    private $options;

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function run()
    {
        $connection = new AMQPStreamConnection(
            $this->options['host'],
            $this->options['port'],
            $this->options['user'],
            $this->options['password'],
            $this->options['vhost']
        );
        $connection->close();
    }
}
