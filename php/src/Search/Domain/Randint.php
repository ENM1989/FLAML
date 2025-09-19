<?php

namespace Flaml\Search\Domain;

class Randint
{
    private $lower;
    private $upper;

    public function __construct(int $lower, int $upper)
    {
        $this->lower = $lower;
        $this->upper = $upper;
    }

    public function sample()
    {
        return rand($this->lower, $this->upper);
    }
}
