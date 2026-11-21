<?php

namespace Sunfox\ServerCheck\Checks;

use PDO;

final class DatabaseCheck implements Check
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
        $connection->query('SELECT 1');
    }
}
