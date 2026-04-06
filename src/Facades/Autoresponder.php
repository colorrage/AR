<?php

namespace CmrManagement\Autoresponder\Facades;

use Illuminate\Support\Facades\Facade;
use CmrManagement\Autoresponder\Services\AutoresponderService;

/**
 * @method static \CmrManagement\Autoresponder\Models\Enrollment enroll($subscriber, \CmrManagement\Autoresponder\Models\Sequence $sequence, array $options = [])
 * @method static void unenroll($subscriber, \CmrManagement\Autoresponder\Models\Sequence $sequence)
 * @method static bool isEnrolled($subscriber, \CmrManagement\Autoresponder\Models\Sequence $sequence)
 * @method static void processEnrollments()
 * @method static void checkTriggers()
 *
 * @see \CmrManagement\Autoresponder\Services\AutoresponderService
 */
class Autoresponder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AutoresponderService::class;
    }
}
