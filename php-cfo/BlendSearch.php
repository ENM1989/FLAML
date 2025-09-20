<?php

namespace Flaml\Tune\Searcher;

require_once 'FLOW2.php';
require_once 'SearchThread.php';
require_once 'utils.php';

use function Flaml\Tune\indexof;

class BlendSearch
{
    // Properties
    public const LAGRANGE = '_lagrange';
    public const PENALTY = 1e10;

    private $metric;
    private $mode;
    private $space;
    private $low_cost_partial_config;
    private $cat_hp_cost;
    private $points_to_evaluate;
    private $evaluated_rewards;
    private $time_budget_s;
    private $num_samples;
    private $resource_attr;
    private $min_resource;
    private $max_resource;
    private $reduction_factor;
    private $global_search_alg;
    private $config_constraints;
    private $metric_constraints;
    private $seed;
    public $cost_attr;
    private $cost_budget;
    private $experimental;
    public $lexico_objectives;
    private $use_incumbent_result_in_evaluation;
    private $allow_empty_config;

    private $_eps;
    private $_input_cost_attr;
    private $_cost_budget;
    private $_metric;
    private $_mode;
    private $_points_to_evaluate;
    private $_evaluated_rewards;
    private $_evaluated_points;
    private $_all_rewards;
    private $_config_constraints;
    private $_metric_constraints;
    private $_cat_hp_cost;
    public $_ls;
    private $_gs;
    private $_experimental;
    private $_candidate_start_points;
    private $_started_from_low_cost;
    private $_time_budget_s;
    private $_num_samples;
    private $_allow_empty_config;
    private $_start_time;
    private $_time_used;
    private $_deadline;
    private $_is_ls_ever_converged;
    private $_subspace;
    private $_metric_target;
    private $_search_thread_pool;
    private $_thread_count;
    private $_init_used;
    private $_trial_proposed_by;
    private $_ls_bound_min;
    private $_ls_bound_max;
    private $_gs_admissible_min;
    private $_gs_admissible_max;
    private $_metric_constraint_satisfied;
    private $_metric_constraint_penalty;
    public $best_resource;
    private $_result;
    private $_cost_used;

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
        $this->metric = $metric;
        $this->mode = $mode;
        $this->space = $space;
        $this->low_cost_partial_config = $low_cost_partial_config;
        $this->cat_hp_cost = $cat_hp_cost;
        $this->points_to_evaluate = $points_to_evaluate;
        $this->evaluated_rewards = $evaluated_rewards;
        $this->time_budget_s = $time_budget_s;
        $this->num_samples = $num_samples;
        $this->resource_attr = $resource_attr;
        $this->min_resource = $min_resource;
        $this->max_resource = $max_resource;
        $this->reduction_factor = $reduction_factor;
        $this->global_search_alg = $global_search_alg;
        $this->config_constraints = $config_constraints;
        $this->metric_constraints = $metric_constraints;
        $this->seed = $seed;
        $this->cost_attr = $cost_attr;
        $this->cost_budget = $cost_budget;
        $this->experimental = $experimental;
        $this->lexico_objectives = $lexico_objectives;
        $this->use_incumbent_result_in_evaluation = $use_incumbent_result_in_evaluation;
        $this->allow_empty_config = $allow_empty_config;

        $this->_eps = 1.0;
        $this->_input_cost_attr = $cost_attr;
        if ($cost_attr == 'auto') {
            if ($time_budget_s !== null) {
                $this->cost_attr = 'time_total_s';
            } else {
                $this->cost_attr = null;
            }
            $this->_cost_budget = null;
        } else {
            $this->cost_attr = $cost_attr;
            $this->_cost_budget = $cost_budget;
        }
        $this->penalty = self::PENALTY;
        $this->_metric = $metric;
        $this->_mode = $mode;
        $this->_use_incumbent_result_in_evaluation = $use_incumbent_result_in_evaluation;
        $this->lexico_objectives = $lexico_objectives;
        $init_config = $low_cost_partial_config ?: [];

        $this->_points_to_evaluate = $points_to_evaluate ?: [];
        $this->_evaluated_rewards = $evaluated_rewards ?: [];

        $this->_config_constraints = $config_constraints;
        $this->_metric_constraints = $metric_constraints;
        if ($metric_constraints) {
            $metric .= self::LAGRANGE;
        }
        $this->_cat_hp_cost = $cat_hp_cost ?: [];
        if ($space) {
            // add_cost_to_space is not implemented yet
        }
        $this->_ls = new FLOW2(
            $init_config,
            $metric,
            $mode,
            $space,
            $resource_attr,
            $min_resource,
            $max_resource,
            $reduction_factor,
            $this->cost_attr,
            $seed,
            $this->lexico_objectives
        );
        // Global search algorithm is not implemented yet
        $this->_gs = null;

        if ($space !== null) {
            $this->_init_search();
        }
    }

    private function _init_search()
    {
        $this->_start_time = microtime(true);
        $this->_time_used = 0;
        $this->_set_deadline();
        $this->_is_ls_ever_converged = false;
        $this->_subspace = [];
        $this->_metric_target = $this->_ls->metric_op * INF;
        $this->_search_thread_pool = [
            0 => new SearchThread($this->_ls->mode, $this->_gs, $this->cost_attr, $this->_eps)
        ];
        $this->_thread_count = 1;
        $this->_init_used = $this->_ls->init_config === null;
        $this->_trial_proposed_by = [];
        $this->_ls_bound_min = $this->_ls->normalize($this->_ls->init_config, true);
        $this->_ls_bound_max = $this->_ls->normalize($this->_ls->init_config, true);
        $this->_gs_admissible_min = $this->_ls_bound_min;
        $this->_gs_admissible_max = $this->_ls_bound_max;

        if ($this->_metric_constraints) {
            $this->_metric_constraint_satisfied = false;
            $this->_metric_constraint_penalty = array_fill(0, count($this->_metric_constraints), $this->penalty);
        } else {
            $this->_metric_constraint_satisfied = true;
            $this->_metric_constraint_penalty = null;
        }
        $this->best_resource = $this->_ls->min_resource;
        $this->_result = [];
        $this->_cost_used = 0;
    }

    private function _set_deadline()
    {
        if ($this->_time_budget_s !== null) {
            $this->_deadline = $this->_time_budget_s + $this->_start_time;
            $this->_set_eps();
        } else {
            $this->_deadline = INF;
        }
    }

    public function suggest(string $trial_id): ?array
    {
        if ($this->_init_used && !$this->_points_to_evaluate) {
            if ($this->_cost_budget && $this->_cost_used >= $this->_cost_budget) {
                return null;
            }
            list($choice, $backup) = $this->_select_thread();
            $config = $this->_search_thread_pool[$choice]->suggest($trial_id);
            if (!$choice && $config !== null && $this->_ls->resource) {
                $config[$this->_ls->resource_attr] = $this->best_resource;
            } elseif ($choice && $config === null) {
                if ($this->_search_thread_pool[$choice]->converged) {
                    unset($this->_search_thread_pool[$choice]);
                }
                return null;
            }
            $space = $this->_search_thread_pool[$choice]->space;
            $skip = $this->_should_skip($choice, $trial_id, $config, $space);
            if ($skip) {
                return null;
            }
            $this->_trial_proposed_by[$trial_id] = $choice;
            $signature = $this->_ls->config_signature($config, $space);
            $this->_result[$signature] = [];
            $this->_subspace[$trial_id] = $space;
        } else {
            if ($this->_points_to_evaluate) {
                $init_config = array_shift($this->_points_to_evaluate);
            } else {
                $init_config = $this->_ls->init_config;
            }
            list($config, $space) = $this->_ls->complete_config($init_config);
            $config_signature = $this->_ls->config_signature($config, $space);
            if (isset($this->_result[$config_signature])) {
                return null;
            }
            $this->_init_used = true;
            $this->_trial_proposed_by[$trial_id] = 0;
            $this->_subspace[$trial_id] = $space;
        }
        return $config;
    }

    public function on_trial_complete(string $trial_id, array $result = null, bool $error = false)
    {
        $thread_id = $this->_trial_proposed_by[$trial_id] ?? null;
        if (isset($this->_search_thread_pool[$thread_id])) {
            $this->_search_thread_pool[$thread_id]->on_trial_complete($trial_id, $result, $error);
            unset($this->_trial_proposed_by[$trial_id]);
        }
        if ($result) {
            $config = $result['config'] ?? [];
            if (!$config) {
                foreach ($result as $key => $value) {
                    if (strpos($key, 'config/') === 0) {
                        $config[substr($key, 7)] = $value;
                    }
                }
            }
            $signature = $this->_ls->config_signature($config, $this->_subspace[$trial_id] ?? []);
            if ($error) {
                unset($this->_result[$signature]);
            } else {
                $this->_cost_used += $result[$this->cost_attr] ?? 0;
                $this->_result[$signature] = $result;
                $objective = $result[$this->_ls->metric];
                if (($objective - $this->_metric_target) * $this->_ls->metric_op < 0) {
                    $this->_metric_target = $objective;
                    if ($this->_ls->resource) {
                        $this->_best_resource = $config[$this->_ls->resource_attr];
                    }
                }
                if ($thread_id === 0 && $this->_create_condition($result)) {
                    $this->_create_thread($config, $result, $this->_subspace[$trial_id] ?? $this->_ls->space);
                }
            }
        }
        if ($thread_id && isset($this->_search_thread_pool[$thread_id])) {
            $this->_clean($thread_id);
        }
        if (isset($this->_subspace[$trial_id])) {
            unset($this->_subspace[$trial_id]);
        }
    }

    private function _select_thread()
    {
        return [0, 0];
    }

    private function _should_skip(int $choice, string $trial_id, ?array $config, array $space): bool
    {
        if ($config === null) {
            return true;
        }
        $config_signature = $this->_ls->config_signature($config, $space);
        return isset($this->_result[$config_signature]);
    }

    private function _create_condition(array $result): bool
    {
        if (count($this->_search_thread_pool) < 2) {
            return true;
        }
        $obj_median = $this->_get_median_objective();
        return $result[$this->_ls->metric] * $this->_ls->metric_op < $obj_median;
    }

    private function _get_median_objective()
    {
        $objectives = [];
        foreach ($this->_search_thread_pool as $id => $thread) {
            if ($id > 0) {
                $objectives[] = $thread->obj_best1;
            }
        }
        sort($objectives);
        $count = count($objectives);
        if ($count == 0) {
            return 0;
        }
        $middle = floor(($count - 1) / 2);
        if ($count % 2) {
            return $objectives[$middle];
        } else {
            return ($objectives[$middle] + $objectives[$middle + 1]) / 2;
        }
    }

    private function _create_thread(array $config, array $result, array $space)
    {
        if ($this->lexico_objectives === null) {
            $obj = $result[$this->_ls->metric];
        } else {
            $obj = [];
            foreach ($this->lexico_objectives['metrics'] as $metric) {
                $obj[$metric] = $result[$metric];
            }
        }
        $this->_search_thread_pool[$this->_thread_count] = new SearchThread(
            $this->_ls->mode,
            $this->_ls->create(
                $config,
                $obj,
                $result[$this->cost_attr] ?? 1,
                $space
            ),
            $this->cost_attr,
            $this->_eps
        );
        $this->_thread_count++;
        $this->_update_admissible_region(
            $this->_ls->unflatten_dict($config),
            $this->_ls_bound_min,
            $this->_ls_bound_max,
            $space,
            $this->_ls->space
        );
    }

    private function _update_admissible_region(array $config, array &$admissible_min, array &$admissible_max, array $subspace, array $space)
    {
        $normalized_config = $this->_ls->normalize($config, true);
        foreach ($admissible_min as $key => &$value) {
            $v = $normalized_config[$key];
            if (is_array($admissible_max[$key])) {
                $domain = $space[$key];
                $choice_v = is_array($v) ? $v[count($v) - 1] : $v;
                $choice = indexof($domain, $choice_v);
                if ($choice !== null) {
                    $this->_update_admissible_region(
                        $v,
                        $admissible_min[$key][$choice],
                        $admissible_max[$key][$choice],
                        $subspace[$key],
                        $domain['categories'][$choice]
                    );
                    if (is_array($admissible_max[$key]) && count($admissible_max[$key]) > count($domain['categories'])) {
                        $normal = ($choice + 0.5) / count($domain['categories']);
                        $admissible_max[$key][count($admissible_max[$key]) - 1] = max($normal, $admissible_max[$key][count($admissible_max[$key]) - 1]);
                        $admissible_min[$key][count($admissible_min[$key]) - 1] = min($normal, $admissible_min[$key][count($admissible_min[$key]) - 1]);
                    }
                }
            } elseif (is_array($v)) {
                $this->_update_admissible_region(
                    $v,
                    $admissible_min[$key],
                    $admissible_max[$key],
                    $subspace[$key],
                    $space[$key]
                );
            } else {
                if ($v > $admissible_max[$key]) {
                    $admissible_max[$key] = $v;
                } elseif ($v < $admissible_min[$key]) {
                    $admissible_min[$key] = $v;
                }
            }
        }
    }

    private function _create_thread_from_best_candidate()
    {
        $best_trial_id = null;
        $obj_best = null;
        foreach ($this->_candidate_start_points as $trial_id => $r) {
            if ($r && ($best_trial_id === null || $r[$this->_ls->metric] * $this->_ls->metric_op < $obj_best)) {
                $best_trial_id = $trial_id;
                $obj_best = $r[$this->_ls->metric] * $this->_ls->metric_op;
            }
        }
        if ($best_trial_id) {
            $config = [];
            $result = $this->_candidate_start_points[$best_trial_id];
            foreach ($result as $key => $value) {
                if (strpos($key, 'config/') === 0) {
                    $config[substr($key, 7)] = $value;
                }
            }
            $this->_started_from_given = true;
            unset($this->_candidate_start_points[$best_trial_id]);
            $this->_create_thread($config, $result, $this->_subspace[$best_trial_id] ?? $this->_ls->space);
        }
    }

    private function _clean(int $thread_id)
    {
        if ($this->_search_thread_pool[$thread_id]->converged) {
            unset($this->_search_thread_pool[$thread_id]);
        }
    }

    private function _set_eps()
    {
        $this->_eps = max(min($this->_time_budget_s / 1000.0, 1.0), 1e-9);
    }
}
