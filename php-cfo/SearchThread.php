<?php

namespace Flaml\Tune\Searcher;

class SearchThread
{
    public $mode;
    public $searcher;
    public $cost_attr;
    public $eps;
    public $running = 0;
    public $obj_best1;
    public $obj_best2;
    public $speed = 0;
    public $eci;
    public $priority = 0;

    public function __construct($mode, $searcher, $cost_attr, $eps)
    {
        $this->mode = $mode;
        $this->searcher = $searcher;
        $this->cost_attr = $cost_attr;
        $this->eps = $eps;
        $this->obj_best1 = $mode === 'min' ? INF : -INF;
        $this->obj_best2 = $mode === 'min' ? INF : -INF;
        $this->eci = $mode === 'min' ? INF : -INF;
    }

    public function on_trial_complete(string $trial_id, array $result = null, bool $error = false)
    {
        $this->running--;
    }

    public function suggest(string $trial_id)
    {
        if ($this->searcher) {
            return $this->searcher->suggest($trial_id);
        }
        return null;
    }

    public function update_eci($metric_target, $max_speed)
    {
        // Not implemented yet
    }

    public function update_priority($min_eci)
    {
        // Not implemented yet
    }

    public function on_trial_result(string $trial_id, array $result)
    {
        // Not implemented yet
    }
}
