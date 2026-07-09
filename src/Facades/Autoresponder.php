<?php

namespace ColorrageAR\Autoresponder\Facades;

use Illuminate\Support\Facades\Facade;
use ColorrageAR\Autoresponder\Services\AutoresponderService;

/**
 * @method static \ColorrageAR\Autoresponder\Models\Enrollment enroll($subscriber, \ColorrageAR\Autoresponder\Models\Sequence $sequence, array $options = [])
 * @method static void unenroll($subscriber, \ColorrageAR\Autoresponder\Models\Sequence $sequence)
 * @method static bool isEnrolled($subscriber, \ColorrageAR\Autoresponder\Models\Sequence $sequence)
 * @method static void processEnrollments()
 * @method static void checkTriggers()
 *
 * @see \ColorrageAR\Autoresponder\Services\AutoresponderService
 */
class Autoresponder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AutoresponderService::class;
    }
}
