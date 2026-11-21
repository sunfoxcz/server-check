# Server Check

An HTTP endpoint for monitoring Redis, MySQL, PostgreSQL, Elasticsearch, RabbitMQ, Supervisor, and custom JSON APIs.

## Installation and startup

For a new installation, copy the example configuration and install the dependencies.

```sh
cp config/config.example.neon config/config.neon
composer install --no-dev
```

Run Composer with the same PHP version as your web server. If your local `composer.lock` was generated with a different PHP version, run `composer update --no-dev` with the target version. Use `composer check-platform-reqs` to verify that the required PHP extensions are available.

Set your service addresses and credentials in `config/config.neon`. Git ignores this file. Make sure the user running PHP can read it. Set the web root to `public/` and configure your monitoring system to send an HTTP GET request to the site URL. To run locally, use `php -S 127.0.0.1:8080 -t public`.

## Configuration

The `checks` section groups checks by type. Give each instance a name to identify it in the admin report. Omit any groups you do not need. This example runs two Redis checks and one Supervisor check.

```neon
adminIps:
    - '127.0.0.1'
checks:
    redis:
        Redis session:
            host: redis-session
            port: 6379
        Redis cache:
            host: redis-cache
            port: 6379
    supervisor:
        Supervisor:
            url: 'http://127.0.0.1:9001/RPC2'
            timeout: 5
```

Set credentials in each check instance. The HTTP settings `timeout` and `requestTimeout` are in seconds. Elasticsearch also has a `health.timeout` setting that is passed to its API and takes a value such as `2s`. See [config/config.example.neon](config/config.example.neon) for examples of every check type.

### Supervisor

The `supervisor` check uses XML-RPC on `/RPC2`. By default, it requires the Supervisor daemon and all managed processes to be `RUNNING`. Set `ignoreStopped: true` to skip processes in the `STOPPED` state. The admin report lists every process that fails the check. [Supervisor XML-RPC API](https://supervisord.org/api.html#getallprocessinfo) The example configuration uses `http://127.0.0.1:9001/RPC2` with placeholder credentials. Omit `username` and `password` if the listener allows unauthenticated requests. If PHP and Supervisor run in separate containers, use an address reachable from the PHP container. The Supervisor interface also provides process controls. For local use, bind it to the loopback interface. [Supervisor configuration](https://supervisord.org/configuration.html#inet-http-server-section-settings)

For password authentication, set a username and password in `supervisord.conf`. Keep the existing `[rpcinterface:supervisor]` section if your configuration already has one.

```ini
[inet_http_server]
port = 127.0.0.1:9001
username = server_check
password = replace-me

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface
```

Restart Supervisor to apply the change. Enter the same credentials in the corresponding instance in `config/config.neon`.

```neon
checks:
    supervisor:
        Supervisor:
            url: 'http://127.0.0.1:9001/RPC2'
            username: server_check
            password: 'replace-me'
            ignoreStopped: true
            timeout: 5
```

### JSON API

Use `jsonApi` for a custom JSON health endpoint. The check sends GET and passes when the response is HTTP 200 with an `application/json` content type. Use the URL for query parameters. On other HTTP status codes, the admin report includes the status, content type, and the value in the optional `errorField` if it contains a string or a list of strings.

```neon
checks:
    jsonApi:
        Application health:
            url: 'https://api.example.com/health?apiKey=replace-me'
            errorField: errors
            timeout: 5
```

### Databases

The `database` check connects through PDO and runs `SELECT 1`. The DSN prefix determines the database engine. Use `pdo_mysql` for MySQL and `pdo_pgsql` for PostgreSQL. The [example configuration](config/config.example.neon) includes both DSNs.

#### MySQL

As a MySQL administrator, create the monitoring database and an account for the address PHP connects from. The example assumes a local client using `mysql:host=127.0.0.1;port=3306;dbname=check`. The grant lets the account select the dedicated monitoring database in the DSN. [MySQL CREATE USER](https://dev.mysql.com/doc/refman/8.4/en/create-user.html)

```sql
CREATE DATABASE IF NOT EXISTS `check`;
CREATE USER 'check'@'127.0.0.1' IDENTIFIED BY 'replace-me';
GRANT SELECT ON `check`.* TO 'check'@'127.0.0.1';
```

#### PostgreSQL

As a PostgreSQL administrator, create the monitoring database and a login role with `CONNECT` permission. The example uses `pgsql:host=127.0.0.1;port=5432;dbname=check`. Connecting to that database is sufficient to run the check query. [PostgreSQL CREATE ROLE](https://www.postgresql.org/docs/current/sql-createrole.html) [PostgreSQL GRANT](https://www.postgresql.org/docs/current/sql-grant.html)

```sql
CREATE DATABASE "check";
CREATE ROLE "check" LOGIN PASSWORD 'replace-me';
GRANT CONNECT ON DATABASE "check" TO "check";
```

Set the username and password for each database instance in `config/config.neon`.

## Endpoint responses

- Monitoring requests receive HTTP 200 with `All checks complete` when every check passes. The first failure returns HTTP 500 with an empty body.
- Requests from an address in `adminIps` receive a detailed report for every check and HTTP 200 even if a service fails. PHP reads the address from `REMOTE_ADDR`. Use `adminIps: []` to disable the detailed report.
- If the configuration file is missing or contains invalid NEON, PHP logs the error and the endpoint returns HTTP 500.

## Tests

```sh
./tests/test-matrix.sh
./tests/test-matrix.sh 5.6 8.6
```

The default run tests all nine `sunfoxcz/php` Docker images for PHP 5.6, 7.4, and 8.0 through 8.6. The tests use the example configuration and local HTTP responses.
