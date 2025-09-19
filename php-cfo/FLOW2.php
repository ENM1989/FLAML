<?php

namespace Flaml\Tune\Searcher;

class FLOW2
{
    // Properties
    public const STEPSIZE = 0.1;
    public const STEP_LOWER_BOUND = 0.0001;

    private $metric;
    private $mode;
    private $space;
    private $init_config;
    private $best_config;
    private $resource_attr;
    private $min_resource;
    private $max_resource;
    private $resource_multiple_factor;
    private $cost_attr;
    private $seed;
    private $lexico_objectives;
    private $metric_op;
    private $_space;
    private $_random;
    private $rs_random;
    private $_resource;
    private $_f_best;
    private $_step_lb;
    private $_histories;
    private $_tunable_keys;
    private $_bounded_keys;
    private $_unordered_cat_hp;
    public $hierarchical;
    private $_space_keys;
    public $incumbent;
    public $best_obj;
    public $cost_incumbent;
    public $dim;
    private $_direction_tried;
    private $_num_complete4incumbent;
    private $_cost_complete4incumbent;
    private $_num_allowed4incumbent;
    private $_proposed_by;
    public $step_ub;
    public $step;
    public $dir;
    private $_configs;
    private $_K;
    private $_iter_best_config;
    public $trial_count_proposed;
    public $trial_count_complete;
    private $_num_proposedby_incumbent;
    private $_reset_times;
    private $_trial_cost;
    private $_same;
    private $_init_phase;
    private $_trunc;


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
        // PHP's mt_srand is equivalent to np.random.RandomState
        mt_srand($this->seed);
        // I will need a PHP library for random number generation that is compatible with numpy's random state.
        // For now, I will use mt_rand().

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
            // In PHP, there is no direct equivalent of `isinstance(domain, dict) and "grid_search" in domain`.
            // I will assume that the space is well-formed and does not contain grid search domains.
            // Also, there is no direct equivalent of `callable(getattr(domain, "get_sampler", None))`.
            // I will assume that all domains are tunable.
            $this->_tunable_keys[] = $key;

            // I will need to implement the logic for handling different types of domains (e.g., uniform, quantized, categorical).
            // For now, I will assume all domains are uniform.
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
        $this->incumbent = $this->normalize($this->best_config); // flattened
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

    private function get_step_lower_bound()
    {
        // I will need to implement the logic for calculating the step lower bound.
        // For now, I will return a constant value.
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
                // Assuming domain is an array with 'lower' and 'upper' bounds for numeric types
                if (is_numeric($value) && isset($domain['lower']) && isset($domain['upper'])) {
                    $normalized_config[$key] = ($value - $domain['lower']) / ($domain['upper'] - $domain['lower']);
                } else {
                    // For categorical data, normalization might mean converting to an index
                    // For now, we'll just pass the value through.
                    $normalized_config[$key] = $value;
                }
            }
        }
        return $normalized_config;
    }

    public function suggest(string $trial_id): ?array
    {
        $this->trial_count_proposed++;
        if ($this->_num_complete4incumbent > 0 &&
            $this->cost_incumbent &&
            $this->_resource &&
            $this->_resource < $this->max_resource &&
            ($this->_cost_complete4incumbent >= $this->cost_incumbent * $this->resource_multiple_factor)
        ) {
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
            // I will need to implement the truncation logic.
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
                // Assuming domain is an array with 'lower' and 'upper' bounds for numeric types
                if (is_numeric($value) && isset($domain['lower']) && isset($domain['upper'])) {
                    $denormalized_config[$key] = $value * ($domain['upper'] - $domain['lower']) + $domain['lower'];
                    if (isset($domain['q'])) {
                        $denormalized_config[$key] = round($denormalized_config[$key] / $domain['q']) * $domain['q'];
                    }
                } else {
                    // For categorical data, denormalization might mean converting an index back to a value
                    // For now, we'll just pass the value through.
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
        // This is a simplified version of the complete_config method.
        // A full implementation would require a more sophisticated way to handle different domain types.
        $config = $partial_config;
        foreach ($this->_space as $key => $domain) {
            if (!isset($config[$key])) {
                // For now, we will just use the lower bound of the domain as the default value.
                if (isset($domain['lower'])) {
                    $config[$key] = $domain['lower'];
                } elseif (isset($domain['categories'])) {
                    $config[$key] = $domain['categories'][0];
                }
            }
        }
        return [$config, $this->space];
    }
}
