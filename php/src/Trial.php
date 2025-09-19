<?php

namespace Flaml;

class Trial
{
    public $config;
    public $result;

    public function __construct($config)
    {
        $this->config = $config;
    }
}
