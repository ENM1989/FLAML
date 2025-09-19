<?php

namespace Flaml;

class Tune
{
    private $search_alg;
    private $trials = [];

    public function run(
        callable $evaluation_function,
        array $config,
        string $metric,
        string $mode,
        float $time_budget_s,
        int $num_samples
    ) {
        $start_time = microtime(true);
        $this->search_alg = new \Flaml\Search\RandomSearch($config);

        for ($i = 0; $i < $num_samples; $i++) {
            if (microtime(true) - $start_time >= $time_budget_s) {
                break;
            }

            $trial_config = $this->search_alg->suggest();

            $trial = new Trial($trial_config);
            $this->trials[] = $trial;

            $trial->result = $evaluation_function($trial->config);
        }

        return $this->get_analysis($metric, $mode);
    }

    private function get_analysis($metric, $mode)
    {
        $best_trial = null;
        $best_metric = $mode === 'min' ? INF : -INF;

        foreach ($this->trials as $trial) {
            if (isset($trial->result[$metric])) {
                if ($mode === 'min') {
                    if ($trial->result[$metric] < $best_metric) {
                        $best_metric = $trial->result[$metric];
                        $best_trial = $trial;
                    }
                } else {
                    if ($trial->result[$metric] > $best_metric) {
                        $best_metric = $trial->result[$metric];
                        $best_trial = $trial;
                    }
                }
            }
        }

        return new ExperimentAnalysis($best_trial, $this->trials);
    }
}
