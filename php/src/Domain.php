<?php

namespace Flaml;

use Flaml\Search\Domain\Lograndint;
use Flaml\Search\Domain\Randint;

class Domain
{
    public static function randint(int $lower, int $upper)
    {
        return new Randint($lower, $upper);
    }

    public static function lograndint(int $lower, int $upper)
    {
        return new Lograndint($lower, $upper);
    }
}
