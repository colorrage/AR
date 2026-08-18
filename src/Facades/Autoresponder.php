<?php

namespace ColorrageAR\Autoresponder\Facades;

use Illuminate\Support\Facades\Facade;
use ColorrageAR\Autoresponder\Services\AutoresponderService;

/**
 * Enrollment entry point for the autoresponder.
 *
 * Enrollment is driven by trigger type, not by sequence: enroll() evaluates every active sequence
 * whose trigger_type matches and returns the enrollments it created. To target one sequence, call
 * AutoresponderService::enrollInSequence() directly.
 *
 * @method static \Illuminate\Support\Collection enroll(string $triggerType, ?\ColorrageAR\Autoresponder\Contracts\Subscribable $subscriber, array $triggerData = [])
 * @method static \ColorrageAR\Autoresponder\Models\Enrollment|null enrollInSequence(\ColorrageAR\Autoresponder\Models\Sequence $sequence, ?\ColorrageAR\Autoresponder\Contracts\Subscribable $subscriber, array $triggerData = [])
 * @method static bool hasActiveEnrollment(\ColorrageAR\Autoresponder\Models\Sequence $sequence, string $email)
 * @method static bool isUnsubscribed(string $email)
 * @method static void pauseEnrollment(\ColorrageAR\Autoresponder\Models\Enrollment $enrollment)
 * @method static void resumeEnrollment(\ColorrageAR\Autoresponder\Models\Enrollment $enrollment)
 * @method static void cancelEnrollment(\ColorrageAR\Autoresponder\Models\Enrollment $enrollment, string $reason = 'manual')
 * @method static void completeEnrollment(\ColorrageAR\Autoresponder\Models\Enrollment $enrollment)
 *
 * Campaigns, mailer lists, CSV import, tokens, and analytics are not exposed through this facade.
 * Resolve those services from the container instead:
 *
 * @see \ColorrageAR\Autoresponder\Services\AutoresponderService  the class this facade proxies
 * @see \ColorrageAR\Autoresponder\Services\CampaignService       sendCampaign, scheduleCampaign, resolveRecipients
 * @see \ColorrageAR\Autoresponder\Services\ListService           mailer list membership
 * @see \ColorrageAR\Autoresponder\Services\ListImportService     CSV import
 * @see \ColorrageAR\Autoresponder\Services\TokenService          token replacement and validation
 * @see \ColorrageAR\Autoresponder\Services\AnalyticsService      sequence, step, campaign, and A/B stats
 */
class Autoresponder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AutoresponderService::class;
    }
}
