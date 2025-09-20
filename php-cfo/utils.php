<?php

namespace Flaml\Tune;

function indexof(array $domain, $value): ?int
{
    if (isset($domain['grid_search'])) {
        $key = array_search($value, $domain['grid_search']);
        return $key === false ? null : $key;
    }
    if (isset($domain['categories'])) {
        $key = array_search($value, $domain['categories']);
        return $key === false ? null : $key;
    }
    return null;
}
