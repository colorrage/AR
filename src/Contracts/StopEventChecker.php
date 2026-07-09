<?php

namespace ColorrageAR\Autoresponder\Contracts;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Step;

interface StopEventChecker
{
    /**
     * Check whether the enrollment should be stopped.
     *
     * @return bool True if the sequence should stop for this enrollment.
     */
    public function shouldStop(Step $step, Enrollment $enrollment): bool;
}
