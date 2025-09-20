<?php

namespace Flaml\Tune\Searcher;

require_once 'BlendSearch.php';

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
                $this->_create_thread_from_best_candidate();
            }
        }
    }
}
