# Server Check

This repository provides an HTTP endpoint for monitoring Redis, MySQL and PostgreSQL, Elasticsearch, RabbitMQ, Supervisor, and custom JSON APIs. The default Git branch is `master`. Write code, comments, messages, and documentation in English.

## Layout and behavior

- Keep `public/index.php` as the HTTP entry point and place application code in `src/`. Checks implement `Sunfox\ServerCheck\Checks\Check` and are registered in `src/Bootstrap.php`.
- The configured check types are `redis`, `database`, `elasticsearch`, `rabbitmq`, `supervisor`, and `jsonApi`. Each type can contain multiple named instances. The `database` check uses PDO and runs `SELECT 1`.
- `supervisor` uses the native XML-RPC interface and checks that the daemon and every managed process are `RUNNING`. The optional `ignoreStopped` flag skips processes in the `STOPPED` state. `jsonApi` checks for HTTP 200 with an `application/json` content type and can report a configured `errorField` on error responses.
- Monitoring requests return HTTP 200 with `All checks complete` after every check passes, or an empty HTTP 500 response on the first failure. Requests from `adminIps` report every check. The client IP comes from `REMOTE_ADDR`.

## Configuration and verification

- Use `config/config.example.neon` for documented examples and `config/config.neon` for local configuration and credentials. Git ignores `config/config.neon`, `vendor/`, and `composer.lock`.
- Keep PHP syntax and Composer dependencies compatible with PHP 5.6. Run Composer under the target PHP version so it selects compatible dependency releases.
- Run `./tests/test-matrix.sh` from the repository root. It covers PHP 5.6, 7.4, and 8.0 through 8.6 in `sunfoxcz/php` Docker images. For a focused run, pass versions such as `./tests/test-matrix.sh 5.6 8.6`. The matrix uses the example configuration and local HTTP fixtures.
