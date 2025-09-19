<?php

require_once __DIR__ . '/vendor/autoload.php';

use Flaml\Tune;
use Flaml\Domain;

function compute_with_config($config)
{
    $start_time = microtime(true);
    $metric2minimize = pow(round($config['x']) - 95000, 2);
    $time2eval = microtime(true) - $start_time;
    return ['metric2minimize' => $metric2minimize, 'time2eval' => $time2eval];
}

$analysis = (new Tune())->run(
    'compute_with_config',
    [
        'x' => Domain::lograndint(1, 1000000),
        'y' => Domain::randint(1, 1000000)
    ],
    'metric2minimize',
    'min',
    10.5, // time_budget_s
    100 // num_samples
);

print_r($analysis->best_result);
print_r($analysis->best_config);
