<?php

/**
 * Procest ScheduleReminderHandler
 *
 * Enqueues a Nextcloud background job that fires an in-app reminder
 * `offsetIso8601` from now. In dry-run mode it returns the computed fire
 * time + rendered message without scheduling anything.
 *
 * @category Service
 * @package  OCA\Procest\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Actions;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\AppInfo\Application;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handler for `scheduleReminder` automatic actions.
 */
class ScheduleReminderHandler implements ActionHandlerInterface
{
    use HandlesTemplates;

    /**
     * Fully-qualified class name of the deferred reminder background job.
     *
     * The job class itself is owned by status-transition-engine / the
     * existing background-job folder; this handler only enqueues against
     * it. Soft-binding via a string avoids a hard dependency before that
     * job class lands.
     */
    private const REMINDER_JOB_CLASS = 'OCA\\Procest\\BackgroundJob\\AutomaticActionReminderJob';

    /**
     * Constructor for ScheduleReminderHandler.
     *
     * @param IJobList        $jobList Nextcloud background job list.
     * @param LoggerInterface $logger  PSR-3 logger.
     *
     * @return void
     */
    public function __construct(
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The action type slug handled by this handler.
     */
    public function type(): string
    {
        return 'scheduleReminder';
    }//end type()

    /**
     * {@inheritDoc}
     *
     * @param array $actionConfig      Resolved action config array.
     * @param array $case              The full case object.
     * @param array $transitionContext Transition context (carries dryRun).
     *
     * @return ActionResult The outcome of scheduling the reminder.
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $offsetIso = (string) ($actionConfig['offsetIso8601'] ?? '');
            $message   = $this->renderTemplate(
                template: (string) ($actionConfig['messageTemplate'] ?? ''),
                case: $case
            );
            $recipient = $this->resolveRecipient(
                recipientRef: (string) ($actionConfig['recipientRef'] ?? ''),
                case: $case
            );

            $fireAt    = $this->computeFireTime(offsetIso: $offsetIso);
            $fireAtIso = null;
            if ($fireAt !== null) {
                $fireAtIso = $fireAt->format(DateTimeInterface::ATOM);
            }

            $preview = [
                'offsetIso8601' => $offsetIso,
                'fireAtIso'     => $fireAtIso,
                'recipient'     => $recipient,
                'message'       => $message,
            ];

            if (($transitionContext['dryRun'] ?? false) === true) {
                return ActionResult::success($preview);
            }

            if ($fireAt === null) {
                return ActionResult::failure('invalid_offset', $preview);
            }

            $arguments = [
                'caseId'    => (string) ($case['id'] ?? ''),
                'recipient' => $recipient,
                'message'   => $message,
                'fireAtIso' => $fireAt->format(DateTimeInterface::ATOM),
            ];

            $this->jobList->add(self::REMINDER_JOB_CLASS, $arguments);

            return ActionResult::success($preview);
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduleReminderHandler: failed to schedule reminder',
                [
                    'app'       => Application::APP_ID,
                    'slug'      => (string) ($actionConfig['slug'] ?? ''),
                    'exception' => $e->getMessage(),
                ]
            );
            return ActionResult::failure('schedule_reminder_failed');
        }//end try
    }//end handle()

    /**
     * Compute the fire-time by adding an ISO 8601 duration offset to now.
     *
     * @param string $offsetIso e.g. `P3D` (3 days), `PT2H` (2 hours).
     *
     * @return DateTimeImmutable|null
     */
    private function computeFireTime(string $offsetIso): ?DateTimeImmutable
    {
        if ($offsetIso === '') {
            return null;
        }

        try {
            $interval = new DateInterval($offsetIso);
        } catch (Throwable $e) {
            return null;
        }

        return (new DateTimeImmutable('now'))->add($interval);
    }//end computeFireTime()
}//end class
