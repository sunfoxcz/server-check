<?php

namespace Sunfox\ServerCheck;

use InvalidArgumentException;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use RuntimeException;
use Sunfox\ServerCheck\Checks\Check;
use Sunfox\ServerCheck\Checks\DatabaseCheck;
use Sunfox\ServerCheck\Checks\ElasticsearchCheck;
use Sunfox\ServerCheck\Checks\JsonApiCheck;
use Sunfox\ServerCheck\Checks\RabbitMqCheck;
use Sunfox\ServerCheck\Checks\RedisCheck;
use Sunfox\ServerCheck\Checks\SupervisorCheck;

final class Bootstrap
{
    public static function run($configDirectory, $remoteAddress)
    {
        try {
            $config = self::readConfig($configDirectory . '/config.neon');
        } catch (RuntimeException $error) {
            error_log($error->getMessage());
            http_response_code(500);
            return;
        }

        $debug = in_array($remoteAddress, $config['adminIps'], true);
        $runner = new CheckRunner();
        $runner->run(self::createChecks($config['checks'], new HttpClient()), $debug);
    }

    private static function readConfig($file)
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("Could not read configuration file $file");
        }

        try {
            return Neon::decode($contents);
        } catch (NeonException $error) {
            throw new RuntimeException("Invalid NEON syntax in $file");
        }
    }

    /** @return iterable<string, Check> */
    private static function createChecks(array $groups, HttpClient $http)
    {
        foreach ($groups as $type => $instances) {
            foreach ($instances as $name => $options) {
                yield (string) $name => self::createCheck($type, $options, $http);
            }
        }
    }

    private static function createCheck($type, array $options, HttpClient $http)
    {
        switch ($type) {
            case 'redis':
                return new RedisCheck($options['host'], $options['port']);
            case 'database':
                return new DatabaseCheck($options['dsn'], $options['user'], $options['password']);
            case 'elasticsearch':
                return new ElasticsearchCheck($http, $options['hosts'], $options['health'], $options['requestTimeout']);
            case 'rabbitmq':
                return new RabbitMqCheck($options);
            case 'supervisor':
                return new SupervisorCheck(
                    $http,
                    $options['url'],
                    $options['timeout'],
                    isset($options['username']) ? $options['username'] : null,
                    isset($options['password']) ? $options['password'] : null,
                    isset($options['ignoreStopped']) ? $options['ignoreStopped'] : false
                );
            case 'jsonApi':
                return new JsonApiCheck(
                    $http,
                    $options['url'],
                    $options['timeout'],
                    isset($options['errorField']) ? $options['errorField'] : null
                );
            default:
                throw new InvalidArgumentException("Unknown check type: $type");
        }
    }
}
