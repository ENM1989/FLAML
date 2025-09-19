<?php

namespace Flaml\Tune\Searcher;

class FLOW2
{
    public const STEPSIZE = 0.1;
    public const STEP_LOWER_BOUND = 0.0001;
    public $init_config;
    public $metric;
    public $mode;
    public $space;
    public $resource_attr;
    public $min_resource;
    public $max_resource;
    public $resource_multiple_factor;
    public $cost_attr;
    public $seed;
    public $lexico_objectives;
    public $metric_op;
    public $_space;
    public $_random;
    public $rs_random;
    public $_resource;
    public $_f_best;
    public $_step_lb;
    public $_histories;
    public $_tunable_keys;
    public $_bounded_keys;
    public $_unordered_cat_hp;
    public $hierarchical;
    public $_space_keys;
    public $incumbent;
    public $best_obj;
    public $cost_incumbent;
    public $dim;
    public $_direction_tried;
    public $_num_complete4incumbent;
    public $_cost_complete4incumbent;
    public $_num_allowed4incumbent;
    public $_proposed_by;
    public $step_ub;
    public $step;
    public $dir;
    public $_configs;
    public $_K;
    public $_iter_best_config;
    public $trial_count_proposed;
    public $trial_count_complete;
    public $_num_proposedby_incumbent;
    public $_reset_times;
    public $_trial_cost;
    public $_same;
    public $_init_phase;
    public $_trunc;

    public function __construct(
        array $init_config,
        string $metric = null,
        string $mode = null,
        array $space = null,
        string $resource_attr = null,
        float $min_resource = null,
        float $max_resource = null,
        float $resource_multiple_factor = null,
        string $cost_attr = 'time_total_s',
        int $seed = 20,
        $lexico_objectives = null
    ) {
        $this->init_config = $init_config;
        $this->metric = $metric;
        $this->mode = $mode ?: 'min';
        $this->space = $space ?: [];
        $this->resource_attr = $resource_attr;
        $this->min_resource = $min_resource;
        $this->max_resource = $max_resource;
        $this->resource_multiple_factor = $resource_multiple_factor;
        $this->cost_attr = $cost_attr;
        $this->seed = $seed;
        $this->lexico_objectives = $lexico_objectives;

        if ($this->mode == 'max') {
            $this->metric_op = -1.0;
        } else {
            $this->metric_op = 1.0;
        }
        $this->_space = $this->flatten_dict($this->space);
        mt_srand($this->seed);
        $this->best_config = $this->flatten_dict($init_config);
        $this->_init_search();
    }
    private function flatten_dict(array $d, string $parent_key = '', string $sep = '/'): array
    {
        $items = [];
        foreach ($d as $k => $v) {
            $new_key = $parent_key ? $parent_key . $sep . $k : $k;
            if (is_array($v) && !empty($v) && array_keys($v) !== range(0, count($v) - 1)) {
                $items = array_merge($items, $this->flatten_dict($v, $new_key, $sep));
            } else {
                $items[$new_key] = $v;
            }
        }
        return $items;
    }
    private function _init_search()
    {
        $this->_tunable_keys = [];
        $this->_bounded_keys = [];
        $this->_unordered_cat_hp = [];
        $this->hierarchical = false;
        foreach ($this->_space as $key => $domain) {
            $this->_tunable_keys[] = $key;
            $this->_bounded_keys[] = $key;
        }
        if (!$this->hierarchical) {
            $this->_space_keys = $this->_tunable_keys;
            sort($this->_space_keys);
        }
        if ($this->resource_attr && !isset($this->_space[$this->resource_attr]) && $this->max_resource) {
            $this->min_resource = $this->min_resource ?: $this->_min_resource();
            $this->_resource = $this->_round($this->min_resource);
            if (!$this->hierarchical) {
                $this->_space_keys[] = $this->resource_attr;
            }
        } else {
            $this->_resource = null;
        }
        $this->incumbent = [];
        $this->incumbent = $this->normalize($this->best_config);
        $this->best_obj = null;
        $this->cost_incumbent = null;
        $this->dim = count($this->_tunable_keys);
        $this->_direction_tried = null;
        $this->_num_complete4incumbent = 0;
        $this->_cost_complete4incumbent = 0;
        $this->_num_allowed4incumbent = 2 * $this->dim;
        $this->_proposed_by = [];
        $this->step_ub = sqrt($this->dim);
        $this->step = self::STEPSIZE * $this->step_ub;
        $lb = $this->get_step_lower_bound();
        if ($lb > $this->step) {
            $this->step = $lb * 2;
        }
        $this->step = min($this->step, $this->step_ub);
        $this->dir = 2 ** (min(9, $this->dim));
        $this->_configs = [];
        $this->_K = 0;
        $this->_iter_best_config = 1;
        $this->trial_count_proposed = 1;
        $this->trial_count_complete = 1;
        $this->_num_proposedby_incumbent = 0;
        $this->_reset_times = 0;
        $this->_trial_cost = [];
        $this->_same = false;
        $this->_init_phase = true;
        $this->_trunc = 0;
    }
    public function get_step_lower_bound()
    {
        return self::STEP_LOWER_BOUND;
    }
    private function _min_resource()
    {
        return $this->max_resource / pow($this->resource_multiple_factor, 5);
    }
    private function _round($resource)
    {
        if ($resource * $this->resource_multiple_factor > $this->max_resource) {
            return $this->max_resource;
        }
        return $resource;
    }
    public function normalize(array $config, bool $recursive = false): array
    {
        $normalized_config = [];
        foreach ($config as $key => $value) {
            if (isset($this->_space[$key])) {
                $domain = $this->_space[$key];
                if (is_numeric($value) && isset($domain['lower']) && isset($domain['upper'])) {
                    $normalized_config[$key] = ($value - $domain['lower']) / ($domain['upper'] - $domain['lower']);
                } else {
                    $normalized_config[$key] = $value;
                }
            }
        }
        return $normalized_config;
    }
    public function suggest(string $trial_id): ?array
    {
        $this->trial_count_proposed++;
        if ($this->_num_complete4incumbent > 0 && $this->cost_incumbent && $this->_resource && $this->_resource < $this->max_resource && ($this->_cost_complete4incumbent >= $this->cost_incumbent * $this->resource_multiple_factor)) {
            return $this->_increase_resource($trial_id);
        }
        $this->_num_allowed4incumbent--;
        $move = $this->incumbent;
        if ($this->_direction_tried !== null) {
            foreach ($this->_tunable_keys as $i => $key) {
                $move[$key] -= $this->_direction_tried[$i];
            }
            $this->_direction_tried = null;
        } else {
            $this->_direction_tried = $this->rand_vector_unit_sphere($this->dim, $this->step);
            foreach ($this->_tunable_keys as $i => $key) {
                $move[$key] += $this->_direction_tried[$i];
            }
        }
        $this->_project($move);
        $config = $this->denormalize($move);
        $this->_proposed_by[$trial_id] = $this->incumbent;
        $this->_configs[$trial_id] = [$config, $this->step];
        $this->_num_proposedby_incumbent++;
        if ($this->_num_proposedby_incumbent == $this->dir && (!$this->_resource || $this->resource == $this->max_resource)) {
            $this->_num_proposedby_incumbent -= 2;
            $this->_init_phase = false;
            if ($this->step < $this->get_step_lower_bound()) {
                return null;
            }
            $this->_oldK = $this->_K ?: $this->_iter_best_config;
            $this->_K = $this->trial_count_proposed + 1;
            $this->step *= sqrt($this->_oldK / $this->_K);
        }
        return $this->unflatten_dict($config);
    }
    private function rand_vector_unit_sphere(int $dim, float $std = 1.0): array
    {
        $vec = [];
        for ($i = 0; $i < $dim; $i++) {
            $vec[] = (mt_rand() / mt_getrandmax() - 0.5) * 2 * $std;
        }
        if ($this->_trunc > 0 && $this->_trunc < $dim) {
        }
        $mag = sqrt(array_sum(array_map(function ($x) {
            return $x * $x;
        }, $vec)));
        return array_map(function ($x) use ($mag) {
            return $x / $mag;
        }, $vec);
    }
    private function _project(array &$config)
    {
        foreach ($this->_bounded_keys as $key) {
            $value = $config[$key];
            $config[$key] = max(0, min(1, $value));
        }
        if ($this->_resource) {
            $config[$this->resource_attr] = $this->_resource;
        }
    }
    public function denormalize(array $config): array
    {
        $denormalized_config = [];
        foreach ($config as $key => $value) {
            if (isset($this->_space[$key])) {
                $domain = $this->_space[$key];
                if (is_numeric($value) && isset($domain['lower']) && isset($domain['upper'])) {
                    $denormalized_config[$key] = $value * ($domain['upper'] - $domain['lower']) + $domain['lower'];
                    if (isset($domain['q'])) {
                        $denormalized_config[$key] = round($denormalized_config[$key] / $domain['q']) * $domain['q'];
                    }
                } else {
                    $denormalized_config[$key] = $value;
                }
            }
        }
        return $denormalized_config;
    }
    public function on_trial_complete(string $trial_id, array $result = null, bool $error = false)
    {
        $this->trial_count_complete++;
        if (!$error && $result) {
            $obj = $result[$this->metric] ?? null;
            if ($obj !== null) {
                $obj *= $this->metric_op;
                if ($this->best_obj === null || $obj < $this->best_obj) {
                    $this->best_obj = $obj;
                    list($this->best_config, $this->step) = $this->_configs[$trial_id];
                    $this->incumbent = $this->normalize($this->best_config);
                    $this->cost_incumbent = $result[$this->cost_attr] ?? 1;
                    if ($this->_resource) {
                        $this->_resource = $this->best_config[$this->resource_attr];
                    }
                    $this->_num_complete4incumbent = 0;
                    $this->_cost_complete4incumbent = 0;
                    $this->_num_proposedby_incumbent = 0;
                    $this->_num_allowed4incumbent = 2 * $this->dim;
                    $this->_proposed_by = [];
                    if ($this->_K > 0) {
                        $this->step *= sqrt($this->_K / $this->_oldK);
                    }
                    $this->step = min($this->step, $this->step_ub);
                    $this->_iter_best_config = $this->trial_count_complete;
                    if ($this->_trunc) {
                        $this->_trunc = min($this->_trunc + 1, $this->dim);
                    }
                    return;
                } elseif ($this->_trunc) {
                    $this->_trunc = max($this->_trunc >> 1, 1);
                }
            }
        }
        $proposed_by = $this->_proposed_by[$trial_id] ?? null;
        if ($proposed_by === $this->incumbent) {
            $this->_num_complete4incumbent++;
            $cost = $result[$this->cost_attr] ?? ($this->_trial_cost[$trial_id] ?? 1);
            if ($cost) {
                $this->_cost_complete4incumbent += $cost;
            }
            if ($this->_num_complete4incumbent >= 2 * $this->dim && $this->_num_allowed4incumbent == 0) {
                $this->_num_allowed4incumbent = 2;
            }
            if ($this->_num_complete4incumbent == $this->dir && (!$this->_resource || $this->_resource == $this->max_resource)) {
                $this->_num_complete4incumbent -= 2;
                $this->_num_allowed4incumbent = max($this->_num_allowed4incumbent, 2);
            }
        }
    }
    public function on_trial_result(string $trial_id, array $result)
    {
        if ($result) {
            $obj = $result[$this->metric] ?? null;
            if ($obj !== null) {
                $obj *= $this->metric_op;
                if ($this->best_obj === null || $obj < $this->best_obj) {
                    $this->best_obj = $obj;
                    $config = $this->_configs[$trial_id][0];
                    if ($this->best_config != $config) {
                        $this->best_config = $config;
                        if ($this->_resource) {
                            $this->_resource = $config[$this->resource_attr];
                        }
                        $this->incumbent = $this->normalize($this->best_config);
                        $this->cost_incumbent = $result[$this->cost_attr] ?? 1;
                        $this->_cost_complete4incumbent = 0;
                        $this->_num_complete4incumbent = 0;
                        $this->_num_proposedby_incumbent = 0;
                        $this->_num_allowed4incumbent = 2 * $this->dim;
                        $this->_proposed_by = [];
                        $this->_iter_best_config = $this->trial_count_complete;
                    }
                }
            }
            $cost = $result[$this->cost_attr] ?? 1;
            $this->_trial_cost[$trial_id] = $cost;
        }
    }
    public function unflatten_dict(array $d): array
    {
        $result = [];
        foreach ($d as $key => $value) {
            $parts = explode('/', $key);
            $temp = &$result;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $temp[$part] = $value;
                } else {
                    if (!isset($temp[$part])) {
                        $temp[$part] = [];
                    }
                    $temp = &$temp[$part];
                }
            }
        }
        return $result;
    }
    private function _increase_resource(string $trial_id)
    {
        $old_resource = $this->_resource;
        $this->_resource = $this->_round($this->_resource * $this->resource_multiple_factor);
        $this->cost_incumbent *= $this->_resource / $old_resource;
        $config = $this->best_config;
        $config[$this->resource_attr] = $this->_resource;
        $this->_direction_tried = null;
        $this->_configs[$trial_id] = [$config, $this->step];
        return $this->unflatten_dict($config);
    }
    public function complete_config(array $partial_config, array $lower = null, array $upper = null): array
    {
        $config = $partial_config;
        foreach ($this->_space as $key => $domain) {
            if (!isset($config[$key])) {
                if (isset($domain['lower'])) {
                    $config[$key] = $domain['lower'];
                } elseif (isset($domain['categories'])) {
                    $config[$key] = $domain['categories'][0];
                }
            }
        }
        return [$config, $this->space];
    }
    public function create(array $init_config, $obj, float $cost, array $space): self
    {
        $flow2 = new self(
            $init_config,
            $this->metric,
            $this->mode,
            $space,
            $this->resource_attr,
            $this->min_resource,
            $this->max_resource,
            $this->resource_multiple_factor,
            $this->cost_attr,
            $this->seed + 1,
            $this->lexico_objectives
        );
        if ($this->lexico_objectives !== null) {
            $flow2->best_obj = [];
            foreach ($obj as $k => $v) {
                $flow2->best_obj[$k] = ($this->lexico_objectives["modes"][$this->lexico_objectives["metrics"]->indexOf($k)] == "max") ? -$v : $v;
            }
        } else {
            $flow2->best_obj = $obj * $this->metric_op;
        }
        $flow2->cost_incumbent = $cost;
        $this->seed += 1;
        return $flow2;
    }
}

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
    public $space;
    public $converged = false;
    public $best_result;

    public function __construct($mode, $searcher, $cost_attr, $eps)
    {
        $this->mode = $mode;
        $this->searcher = $searcher;
        $this->cost_attr = $cost_attr;
        $this->eps = $eps;
        $this->obj_best1 = $mode === 'min' ? INF : -INF;
        $this->obj_best2 = $mode === 'min' ? INF : -INF;
        $this->eci = $mode === 'min' ? INF : -INF;
        if ($searcher) {
            $this->space = $searcher->space;
        }
    }
    public function on_trial_complete(string $trial_id, array $result = null, bool $error = false)
    {
        $this->running--;
        if ($this->searcher) {
            $this->searcher->on_trial_complete($trial_id, $result, $error);
            $this->obj_best1 = $this->searcher->best_obj;
            $this->best_result = $result;
            $this->converged = $this->searcher->converged;
        }
    }
    public function suggest(string $trial_id)
    {
        if ($this->searcher) {
            $this->running++;
            return $this->searcher->suggest($trial_id);
        }
        return null;
    }
    public function update_eci($metric_target, $max_speed)
    {
    }
    public function update_priority($min_eci)
    {
    }
    public function on_trial_result(string $trial_id, array $result)
    {
        if ($this->searcher) {
            $this->searcher->on_trial_result($trial_id, $result);
            $this->obj_best1 = $this->searcher->best_obj;
        }
    }
}

class BlendSearch
{
    public const LAGRANGE = '_lagrange';
    public const PENALTY = 1e10;
    public $metric;
    public $mode;
    public $space;
    public $low_cost_partial_config;
    public $cat_hp_cost;
    public $points_to_evaluate;
    public $evaluated_rewards;
    public $time_budget_s;
    public $num_samples;
    public $resource_attr;
    public $min_resource;
    public $max_resource;
    public $reduction_factor;
    public $global_search_alg;
    public $config_constraints;
    public $metric_constraints;
    public $seed;
    public $cost_attr;
    public $cost_budget;
    public $experimental;
    public $lexico_objectives;
    public $use_incumbent_result_in_evaluation;
    public $allow_empty_config;
    public $_eps;
    public $_input_cost_attr;
    public $_cost_budget;
    public $_metric;
    public $_mode;
    public $_points_to_evaluate;
    public $_evaluated_rewards;
    public $_evaluated_points;
    public $_all_rewards;
    public $_config_constraints;
    public $_metric_constraints;
    public $_cat_hp_cost;
    public $_ls;
    public $_gs;
    public $_experimental;
    public $_candidate_start_points;
    public $_started_from_low_cost;
    public $_time_budget_s;
    public $_num_samples;
    public $_allow_empty_config;
    public $_start_time;
    public $_time_used;
    public $_deadline;
    public $_is_ls_ever_converged;
    public $_subspace;
    public $_metric_target;
    public $_search_thread_pool;
    public $_thread_count;
    public $_init_used;
    public $_trial_proposed_by;
    public $_ls_bound_min;
    public $_ls_bound_max;
    public $_gs_admissible_min;
    public $_gs_admissible_max;
    public $_metric_constraint_satisfied;
    public $_metric_constraint_penalty;
    public $best_resource;
    public $_result;
    public $_cost_used;

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
            if (!$choice && $config !== null && $this->_ls->_resource) {
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
                    if ($this->_ls->_resource) {
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
    protected function _create_condition(array $result): bool
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
        $obj = $result[$this->_ls->metric];
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
    public function get_best_config()
    {
        $best_obj = null;
        $best_config = null;
        foreach ($this->_result as $result) {
            if ($result) {
                $obj = $result[$this->_metric];
                if ($best_obj === null || $obj * $this->_ls->metric_op < $best_obj * $this->_ls->metric_op) {
                    $best_obj = $obj;
                    $best_config = $result['config'];
                }
            }
        }
        return $best_config;
    }
}

class CFO extends BlendSearch
{
    public function suggest(string $trial_id): ?array
    {
        if (count($this->_search_thread_pool) < 2) {
            $this->_init_used = false;
        }
        return parent::suggest($trial_id);
    }
    protected function _create_condition(array $result): bool
    {
        if ($this->_points_to_evaluate) {
            return false;
        }
        if (count($this->_search_thread_pool) == 2) {
            return false;
        }
        return true;
    }
    public function on_trial_complete(string $trial_id, array $result = null, bool $error = false)
    {
        parent::on_trial_complete($trial_id, $result, $error);
        if (isset($this->_candidate_start_points[$trial_id])) {
            $this->_candidate_start_points[$trial_id] = $result;
            if (count($this->_search_thread_pool) < 2 && !$this->_points_to_evaluate) {
            }
        }
    }
}

class FLOW2Cat extends FLOW2
{
    public function _init_search()
    {
        parent::_init_search();
        $this->step_ub = 1;
        $this->step = self::STEPSIZE * $this->step_ub;
        $lb = $this->get_step_lower_bound();
        if ($lb > $this->step) {
            $this->step = $lb * 2;
        }
        if ($this->step > $this->step_ub) {
            $this->step = $this->step_ub;
        }
        $this->_trunc = $this->dim;
    }
}

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
