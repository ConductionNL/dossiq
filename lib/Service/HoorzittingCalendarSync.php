<?php

/**
 * Procest Hoorzitting Calendar Sync.
 *
 * Best-effort transport that mirrors a `hearingSession` (hoorzitting,
 * Awb art. 7:2) into the Nextcloud Calendar and produces an ICS body
 * suitable for sending invitations to the bezwaarmaker, vertegenwoordiger
 * and commissieleden.
 *
 * Design contract (per the bezwaar-beroep-workflow change):
 *
 *  - The canonical hearing record always lives in OpenRegister; the
 *    calendar is only a transport. This service therefore NEVER blocks
 *    the hearing record: when the calendar manager is unavailable or the
 *    sync throws, it records a `calendar-sync-failed` entry in the
 *    hearingSession `auditTrail` and returns the session unchanged
 *    otherwise. Callers persist the returned record regardless.
 *  - When a hearing is waived (`hearingWaived = true`) no calendar event
 *    is produced — there is nothing to schedule.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a hearingSession into the Nextcloud Calendar (best-effort) and
 * builds the invitation ICS.
 *
 * @spec openspec/changes/bezwaar-beroep-workflow/specs/bezwaar-beroep-workflow/spec.md
 */
class HoorzittingCalendarSync
{
    /**
     * Default hearing duration in minutes when no endDate is supplied.
     */
    private const DEFAULT_DURATION_MINUTES = 60;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container Service container (optional
     *                                      calendar manager resolution).
     * @param LoggerInterface    $logger    Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Synchronise a hearingSession with the calendar (best-effort).
     *
     * Returns the (possibly augmented) hearingSession record. On any
     * failure the record gains a `calendar-sync-failed` audit entry but is
     * never rejected; on success it gains a `calendarIcs` body and a
     * `calendar-synced` audit entry.
     *
     * @param array<string, mixed> $hearingSession The hearingSession record.
     *
     * @return array<string, mixed> The hearingSession record to persist.
     *
     * @spec openspec/changes/bezwaar-beroep-workflow/specs/bezwaar-beroep-workflow/spec.md
     */
    public function sync(array $hearingSession): array
    {
        // A waived hearing has nothing to schedule.
        if (($hearingSession['hearingWaived'] ?? false) === true) {
            return $hearingSession;
        }

        $scheduled = $this->parseDate(value: ($hearingSession['scheduledDate'] ?? null));
        if ($scheduled === null) {
            return $this->appendAudit(
                session: $hearingSession,
                event: 'calendar-sync-skipped',
                detail: 'no valid scheduledDate'
            );
        }

        try {
            $ics = $this->buildIcs(hearingSession: $hearingSession, scheduled: $scheduled);
            if ($ics === null) {
                // Calendar manager unavailable — degrade gracefully.
                return $this->appendAudit(
                    session: $hearingSession,
                    event: 'calendar-sync-skipped',
                    detail: 'calendar manager unavailable'
                );
            }

            $hearingSession['calendarIcs'] = $ics;
            return $this->appendAudit(
                session: $hearingSession,
                event: 'calendar-synced',
                detail: 'ICS invitation generated for '
                    .count($this->collectInviteeEmails(hearingSession: $hearingSession))
                    .' invitee(s)'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'HoorzittingCalendarSync: calendar sync failed; hearing record kept',
                ['error' => $e->getMessage()]
            );

            return $this->appendAudit(
                session: $hearingSession,
                event: 'calendar-sync-failed',
                detail: $e->getMessage()
            );
        }//end try
    }//end sync()

    /**
     * Build the ICS invitation body for a hearing.
     *
     * @param array<string, mixed> $hearingSession The hearingSession record.
     * @param DateTimeImmutable    $scheduled      The hearing start.
     *
     * @return string|null The ICS body, or null when the calendar manager
     *                     is unavailable.
     */
    private function buildIcs(array $hearingSession, DateTimeImmutable $scheduled): ?string
    {
        $manager = $this->resolveCalendarManager();
        if ($manager === null) {
            return null;
        }

        $end = $this->parseDate(value: ($hearingSession['endDate'] ?? null));
        if ($end === null) {
            $end = $scheduled->modify('+'.self::DEFAULT_DURATION_MINUTES.' minutes');
        }

        $builder = $manager->createEventBuilder();
        $builder->setStartDate($scheduled);
        $builder->setEndDate($end);
        $builder->setSummary('Hoorzitting bezwaar (Awb art. 7:2)');
        $builder->setDescription(
            (string) ($hearingSession['minutesSummary'] ?? 'Hoorzitting in het kader van de bezwaarprocedure.')
        );

        $location = trim((string) ($hearingSession['location'] ?? ''));
        if ($location !== '') {
            $builder->setLocation($location);
        }

        foreach ($this->collectInviteeEmails(hearingSession: $hearingSession) as $email => $name) {
            $commonName = null;
            if ($name !== '') {
                $commonName = $name;
            }

            $builder->addAttendee($email, $commonName);
        }

        return $builder->toIcs();
    }//end buildIcs()

    /**
     * Collect invitee email addresses (keyed by email, value = name).
     *
     * @param array<string, mixed> $hearingSession The hearingSession record.
     *
     * @return array<string, string> Map of email => display name.
     */
    private function collectInviteeEmails(array $hearingSession): array
    {
        $invitees = ($hearingSession['invitees'] ?? []);
        if (is_string($invitees) === true) {
            $decoded  = json_decode($invitees, true);
            $invitees = [];
            if (is_array($decoded) === true) {
                $invitees = $decoded;
            }
        }

        $emails = [];
        if (is_array($invitees) === true) {
            foreach ($invitees as $invitee) {
                if (is_array($invitee) === false) {
                    continue;
                }

                $email = trim((string) ($invitee['email'] ?? ''));
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $emails[$email] = trim((string) ($invitee['name'] ?? ''));
            }
        }

        return $emails;
    }//end collectInviteeEmails()

    /**
     * Resolve the optional Nextcloud Calendar manager from the container.
     *
     * @return \OCP\Calendar\IManager|null The manager, or null when the
     *                                     Calendar app/API is unavailable.
     */
    private function resolveCalendarManager(): ?\OCP\Calendar\IManager
    {
        try {
            $manager = $this->container->get(\OCP\Calendar\IManager::class);
        } catch (\Throwable $e) {
            $this->logger->info(
                'HoorzittingCalendarSync: calendar manager unavailable',
                ['error' => $e->getMessage()]
            );
            return null;
        }

        if ($manager instanceof \OCP\Calendar\IManager) {
            return $manager;
        }

        return null;
    }//end resolveCalendarManager()

    /**
     * Append a calendar audit entry to the hearingSession.
     *
     * @param array<string, mixed> $session The hearingSession record.
     * @param string               $event   The audit event name.
     * @param string               $detail  Human-readable detail.
     *
     * @return array<string, mixed> The session with the audit entry added.
     */
    private function appendAudit(array $session, string $event, string $detail): array
    {
        $audit = ($session['auditTrail'] ?? []);
        if (is_array($audit) === false) {
            $audit = [];
        }

        $audit[] = [
            'event'   => $event,
            'tag'     => 'calendar',
            'at'      => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'payload' => ['detail' => $detail],
        ];

        $session['auditTrail'] = $audit;
        return $session;
    }//end appendAudit()

    /**
     * Parse an ISO date/time value into an immutable date.
     *
     * @param mixed $value The raw value.
     *
     * @return DateTimeImmutable|null The parsed date, or null.
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return null;
        }
    }//end parseDate()
}//end class
