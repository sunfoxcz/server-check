<?php

namespace Sunfox\ServerCheck\Checks;

interface Check
{
    /** @throws \Exception When the check fails. */
    public function run();
}
