<?php

namespace Flaml\Search;

class RandomSearch
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function suggest()
    {
        $suggestion = [];
        foreach ($this->config as $key => $domain) {
            $suggestion[$key] = $domain->sample();
        }
        return $suggestion;
    }
}
