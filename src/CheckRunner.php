<?php

namespace Sunfox\ServerCheck;

use Exception;
use Sunfox\ServerCheck\Checks\Check;
use Throwable;

final class CheckRunner
{
    /** @param iterable<string, Check> $checks */
    public function run($checks, $debug)
    {
        foreach ($checks as $name => $check) {
            $failure = null;
            try {
                $check->run();
            } catch (Exception $error) {
                $failure = $error;
            } catch (Throwable $error) {
                $failure = $error;
            }

            if ($failure !== null && !$debug) {
                http_response_code(500);
                return;
            }

            if ($debug) {
                $service = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ($failure === null) {
                    echo "$service: OK<br>";
                } else {
                    $message = nl2br(htmlspecialchars($failure->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                    echo "$service error: $message<br>";
                }
                flush();
            }
        }

        echo 'All checks complete';
    }
}
