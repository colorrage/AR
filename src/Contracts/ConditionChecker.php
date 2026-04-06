<?php

namespace CmrManagement\Autoresponder\Contracts;

use CmrManagement\Autoresponder\Models\Enrollment;
use CmrManagement\Autoresponder\Models\Step;

interface ConditionChecker
{
    /**
     * Check whether the step condition is met for the given enrollment.
     *
     * @return bool True if the step should be sent, false to skip.
     */
    public function check(Step $step, Enrollment $enrollment): bool;
}
