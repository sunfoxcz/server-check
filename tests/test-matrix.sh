#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d)
trap 'rm -rf -- "$workspace"' 0
trap 'exit 1' HUP INT TERM
mkdir "$workspace/cache"

if [ "$#" -eq 0 ]; then
    set -- 5.6 7.4 8.0 8.1 8.2 8.3 8.4 8.5 8.6
fi

result=0
for version do
    case "$version" in
        5.6|7.4|8.0|8.1|8.2|8.3|8.4|8.5|8.6) ;;
        *) printf 'Unsupported PHP version: %s\n' "$version" >&2; exit 2 ;;
    esac

    checkout="$workspace/php-$version"
    mkdir -p "$checkout/config"
    cp "$root/composer.json" "$checkout/"
    cp "$root/config/config.example.neon" "$checkout/config/"
    cp -R "$root/public" "$root/src" "$root/tests" "$checkout/"

    printf '\nPHP %s\n' "$version"
    if ! docker run --rm \
        --volume "$checkout:/app" \
        --volume "$workspace/cache:/composer-cache" \
        --env COMPOSER_CACHE_DIR=/composer-cache \
        --workdir /app \
        --user "$(id -u):$(id -g)" \
        "sunfoxcz/php:$version-cli" sh -ec '
            php -r "echo PHP_VERSION, PHP_EOL;"
            composer install --no-interaction --no-progress
            composer check-platform-reqs
            for file in public/*.php src/*.php src/Checks/*.php tests/*.php; do
                php -l "$file" >/dev/null
            done
            php -S 127.0.0.1:18080 tests/http-fixture.php >/tmp/server-check-http.log 2>&1 &
            fixture=$!
            trap "kill $fixture 2>/dev/null || :" 0
            php -r "for (\$attempt = 0; \$attempt < 100; \$attempt++) {
                \$socket = @fsockopen(\"127.0.0.1\", 18080);
                if (\$socket !== false) {
                    fclose(\$socket);
                    exit(0);
                }
                usleep(30000);
            }
            exit(1);"
            php tests/smoke.php
        '; then
        printf 'PHP %s failed\n' "$version" >&2
        result=1
    fi
done

exit "$result"
