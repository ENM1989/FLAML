<?php

namespace Flaml\Tune\Searcher;

require_once 'FLOW2.php';

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
