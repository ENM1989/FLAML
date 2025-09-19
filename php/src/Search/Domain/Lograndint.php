<?php

namespace Flaml\Search\Domain;

class Lograndint
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
        $log_lower = log($this->lower);
        $log_upper = log($this->upper);
        $log_sample = mt_rand() / mt_getrandmax() * ($log_upper - $log_lower) + $log_lower;
        return round(exp($log_sample));
    }
}
