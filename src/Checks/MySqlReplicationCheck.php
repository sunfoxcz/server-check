<?php

namespace Sunfox\ServerCheck\Checks;

use PDO;
use RuntimeException;

final class MySqlReplicationCheck implements Check
{
    private $dsn;
    private $user;
    private $password;

    public function __construct($dsn, $user, $password)
    {
        $this->dsn = $dsn;
        $this->user = $user;
        $this->password = $password;
    }

    public function run()
    {
        $connection = new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $result = $connection->query(
            'SELECT CHANNEL_NAME, SERVICE_STATE, LAST_ERROR_NUMBER, LAST_ERROR_MESSAGE '
            . 'FROM performance_schema.replication_connection_status'
        );
        $channels = $result->fetchAll(PDO::FETCH_ASSOC);
        if ($channels === []) {
            throw new RuntimeException('No MySQL replication channels found');
        }

        $failures = [];
        foreach ($channels as $channel) {
            $state = $channel['SERVICE_STATE'];
            $errorNumber = (string) $channel['LAST_ERROR_NUMBER'];
            if ($state === 'ON' && $errorNumber === '0') {
                continue;
            }

            $name = $channel['CHANNEL_NAME'] === '' ? 'default' : $channel['CHANNEL_NAME'];
            $failure = sprintf('Replication channel "%s" is %s (last error %s)', $name, $state, $errorNumber);
            if ($channel['LAST_ERROR_MESSAGE'] !== '') {
                $failure .= "\n" . $channel['LAST_ERROR_MESSAGE'];
            }

            $failures[] = $failure;
        }

        if ($failures !== []) {
            throw new RuntimeException(implode("\n", $failures));
        }
    }
}
