<?php

namespace Flaml;

class ExperimentAnalysis
{
    public $best_trial;
    public $best_config;
    public $best_result;
    public $trials;

    public function __construct($best_trial, $trials)
    {
        $this->best_trial = $best_trial;
        $this->best_config = $best_trial ? $best_trial->config : null;
        $this->best_result = $best_trial ? $best_trial->result : null;
        $this->trials = $trials;
    }
}
