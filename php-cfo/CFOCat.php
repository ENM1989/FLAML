<?php

namespace Flaml\Tune\Searcher;

require_once 'CFO.php';
require_once 'FLOW2Cat.php';

class CFOCat extends CFO
{
    public function __construct(
        string $metric = null,
        string $mode = null,
        array $space = null,
        array $low_cost_partial_config = null,
        array $cat_hp_cost = null,
        array $points_to_evaluate = null,
        array $evaluated_rewards = null,
        $time_budget_s = null,
        int $num_samples = null,
        string $resource_attr = null,
        float $min_resource = null,
        float $max_resource = null,
        float $reduction_factor = null,
        $global_search_alg = null,
        array $config_constraints = null,
        array $metric_constraints = null,
        int $seed = 20,
        string $cost_attr = 'auto',
        float $cost_budget = null,
        bool $experimental = false,
        $lexico_objectives = null,
        bool $use_incumbent_result_in_evaluation = false,
        bool $allow_empty_config = false
    ) {
        parent::__construct(
            $metric,
            $mode,
            $space,
            $low_cost_partial_config,
            $cat_hp_cost,
            $points_to_evaluate,
            $evaluated_rewards,
            $time_budget_s,
            $num_samples,
            $resource_attr,
            $min_resource,
            $max_resource,
            $reduction_factor,
            $global_search_alg,
            $config_constraints,
            $metric_constraints,
            $seed,
            $cost_attr,
            $cost_budget,
            $experimental,
            $lexico_objectives,
            $use_incumbent_result_in_evaluation,
            $allow_empty_config
        );
        $this->_ls = new FLOW2Cat(
            $this->low_cost_partial_config ?: [],
            $this->metric,
            $this->mode,
            $this->space,
            $this->resource_attr,
            $this->min_resource,
            $this->max_resource,
            $this->reduction_factor,
            $this->cost_attr,
            $this->seed,
            $this->lexico_objectives
        );
    }
}
