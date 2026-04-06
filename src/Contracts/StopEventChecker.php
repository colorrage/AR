<?php

namespace CmrManagement\Autoresponder\Contracts;

use CmrManagement\Autoresponder\Models\Enrollment;
use CmrManagement\Autoresponder\Models\Step;

interface StopEventChecker
{
    /**
     * Check whether the enrollment should be stopped.
     *
     * @return bool True if the sequence should stop for this enrollment.
     */
    public function shouldStop(Step $step, Enrollment $enrollment): bool;
}
