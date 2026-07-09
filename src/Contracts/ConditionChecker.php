<?php

namespace ColorrageAR\Autoresponder\Contracts;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Step;

interface ConditionChecker
{
    /**
     * Check whether the step condition is met for the given enrollment.
     *
     * @return bool True if the step should be sent, false to skip.
     */
    public function check(Step $step, Enrollment $enrollment): bool;
}
