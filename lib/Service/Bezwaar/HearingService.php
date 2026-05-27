<?php

/**
 * Procest Bezwaar Hearing Service.
 *
 * Domain service for the bezwaar-hearing (hoorzitting) capability under
 * Awb Art. 7:2 – 7:7. Owns the legitimate domain operations that cannot
 * be expressed by the manifest-driven CRUD path:
 *
 *  - schedule()           — Create a gepland hearingSession with the
 *                           Awb art. 7:4 lid 2 inspection-of-file floor
 *                           (≥ 7 days before scheduledDate) and an
 *                           awb-art-7:2 audit entry.
 *  - waive()              — Record an art. 7:3 waiver (afzien van het
 *                           hoorrecht) with a non-empty reason and an
 *                           awb-art-7:3 audit entry.
 *  - recordAttendance()   — Append-only attendance capture with a
 *                           one-hour grace window after the hearing
 *                           concludes; later corrections require a
 *                           documented correctionReason and write an
 *                           awb-art-7:7 audit entry.
 *  - addMinutes()         — Promote the session to uitgevoerd when
 *                           minutesSummary or minutesDocument is set;
 *                           audioRecording is only accepted when
 *                           recordingConsent = granted (AVG art. 6).
 *
 * Identity is ALWAYS derived from IUserSession. Per the per-app
 * convention every mutation goes through OpenRegister via the manifest
 * renderer; this service composes those calls and writes the
 * append-only `auditTrail` entries tagged with the applicable Awb
 * article so that beroep dossier export can demonstrate compliance.
 *
 * @category Service
 * @package  OCA\Procest\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Procest\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\Service\SettingsService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hearing service: scheduling, waiver, attendance and minutes capture.
 *
 * @spec openspec/changes/bezwaar-hearing/specs/bezwaar-hearing/spec.md
 */
class HearingService
{
    /**
     * Awb art. 7:4 lid 2 inspection-of-file floor in days.
     */
    private const INSPECTION_FLOOR_DAYS = 7;

    /**
     * Grace window after `scheduledDate` during which the attendance
     * array remains append-only. Past this window, mutations require a
     * documented correctionReason.
     */
    private const ATTENDANCE_GRACE_HOURS = 1;

    /**
     * Audit-tag catalogue covering the legally relevant events on a
     * hearingSession (REQ-BH-8). Values are the canonical tags every
     * downstream consumer (beroep export, accessibility report) reads.
     */
    public const TAG_SCHEDULED       = 'awb-art-7:2';
    public const TAG_INVITATION_SENT = 'awb-art-7:2';
    public const TAG_WAIVER          = 'awb-art-7:3';
    public const TAG_INSPECTION      = 'awb-art-7:4';
    public const TAG_CONFIDENTIAL_WITHELD = 'awb-art-7:6';
    public const TAG_VERSLAG           = 'awb-art-7:7';
    public const TAG_BAC_REFERRAL      = 'awb-art-7:13';
    public const TAG_RECORDING_CONSENT = 'avg-art-6';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Schema/register bridge
     * @param IUserSession    $userSession     Acting identity source
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Schedule a hearing for a bezwaar case (REQ-BH-2 happy path).
     *
     * Creates a hearingSession with status = gepland, computes the
     * art. 7:4 inspection deadline as scheduledDate − 7 days, and
     * appends an awb-art-7:2 audit entry to the new session.
     *
     * @param string               $caseId        UUID of the parent bezwaar case
     * @param string               $scheduledDate ISO-8601 date-time of the hearing
     * @param string               $chairpersonId UUID of the voorzitter role
     * @param array<int, array>    $invitees      Invitee objects (see schema)
     * @param array<string, mixed> $payload       Optional extras (location, videoCallUrl, members, inspectionAvailableFrom, ...)
     *
     * @return array<string, mixed> The persisted hearingSession record
     *
     * @throws RuntimeException When OpenRegister is unavailable, the
     *                          inspection-of-file floor is violated, or
     *                          schemas are unconfigured.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function schedule(
        string $caseId,
        string $scheduledDate,
        string $chairpersonId,
        array $invitees,
        array $payload=[]
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(
            key: 'hearing_session_schema'
        );

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Hearing schema is not configured');
        }

        if ($caseId === '' || $chairpersonId === '' || $invitees === []) {
            throw new RuntimeException(
                'case, chairperson and at least one invitee are required'
            );
        }

        $scheduled = $this->parseDateTime(value: $scheduledDate);
        $deadline  = $this->computeInspectionDeadline(scheduled: $scheduled);
        $now       = new DateTimeImmutable();

        $this->guardInspectionFloor(
            scheduled: $scheduled,
            today: $now,
        );

        $available = $now->setTime(0, 0, 0);
        if (isset($payload['inspectionAvailableFrom']) === true) {
            $available = $this->parseDate(value: (string) $payload['inspectionAvailableFrom']);
        }

        if ($available > $deadline) {
            // Per design.md: inspectionAvailableFrom must be ≤ inspectionDeadline.
            $available = $deadline;
        }

        $record = array_merge(
            [
                'location'     => null,
                'videoCallUrl' => null,
                'members'      => [],
            ],
            $payload,
            [
                'case'                    => $caseId,
                'scheduledDate'           => $scheduled->format(
                    DateTimeInterface::ATOM
                ),
                'chairperson'             => $chairpersonId,
                'invitees'                => $this->stampInvitees(
                    invitees: $invitees,
                    when: $now,
                ),
                'inspectionAvailableFrom' => $available->format('Y-m-d'),
                'inspectionDeadline'      => $deadline->format('Y-m-d'),
                'status'                  => 'gepland',
                'hearingWaived'           => false,
                'recordingConsent'        => $payload['recordingConsent'] ?? 'not_requested',
            ]
        );

        $record['auditTrail'] = $this->appendAudit(
            existing: [],
            event: 'hearing-scheduled',
            tag: self::TAG_SCHEDULED,
            payload: [
                'case'               => $caseId,
                'scheduledDate'      => $record['scheduledDate'],
                'inspectionDeadline' => $record['inspectionDeadline'],
            ],
        );

        try {
            return $objectService->saveObject($register, $schema, $record);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest hearing: failed to schedule hearing: '.$e->getMessage()
            );
            throw new RuntimeException('Could not schedule hearing');
        }
    }//end schedule()

    /**
     * Record an art. 7:3 waiver: bezwaarmaker afziet van het hoorrecht.
     *
     * Creates a hearingSession with status = afzien, hearingWaived = true
     * and the supplied reason. Writes an awb-art-7:3 audit entry.
     *
     * @param string               $caseId  UUID of the parent bezwaar case
     * @param string               $reason  Non-empty waiver reason
     * @param array<string, mixed> $payload Optional extras
     *
     * @return array<string, mixed> The persisted hearingSession record
     *
     * @throws RuntimeException When the reason is empty or persistence fails.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function waive(
        string $caseId,
        string $reason,
        array $payload=[]
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(
                'Waiver reason is required (Awb art. 7:3)'
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(
            key: 'hearing_session_schema'
        );

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Hearing schema is not configured');
        }

        $now = new DateTimeImmutable();

        $record = array_merge(
            $payload,
            [
                'case'                    => $caseId,
                'scheduledDate'           => $now->format(DateTimeInterface::ATOM),
                'chairperson'             => $payload['chairperson'] ?? ($payload['chairpersonId'] ?? 'system'),
                'invitees'                => $payload['invitees'] ?? [],
                'inspectionAvailableFrom' => $now->format('Y-m-d'),
                'inspectionDeadline'      => $now->format('Y-m-d'),
                'status'                  => 'afgezien',
                'hearingWaived'           => true,
                'waiverReason'            => $reason,
            ]
        );

        $record['auditTrail'] = $this->appendAudit(
            existing: [],
            event: 'hearing-waived',
            tag: self::TAG_WAIVER,
            payload: [
                'case'   => $caseId,
                'reason' => $reason,
            ],
        );

        try {
            return $objectService->saveObject($register, $schema, $record);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest hearing: failed to record waiver: '.$e->getMessage()
            );
            throw new RuntimeException('Could not record waiver');
        }
    }//end waive()

    /**
     * Record attendance on a hearingSession (REQ-BH-5).
     *
     * Within the one-hour grace window after the hearing concludes
     * attendance entries SHALL be appendable freely; after the window
     * any correction SHALL carry a non-empty correctionReason that is
     * logged as an awb-art-7:7 audit entry.
     *
     * @param string                           $sessionId UUID of the hearingSession
     * @param array<int, array<string, mixed>> $entries   Attendance entries: each {invitee, present, arrivalTime?, correctionReason?}
     *
     * @return array<string, mixed> The updated hearingSession record
     *
     * @throws RuntimeException When the session is not found, persistence fails, or a late correction lacks a reason.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function recordAttendance(
        string $sessionId,
        array $entries
    ): array {
        if ($entries === []) {
            throw new RuntimeException(
                'At least one attendance entry is required'
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(
            key: 'hearing_session_schema'
        );

        $current = $objectService->find($sessionId, register: $register, schema: $schema);
        if (is_array($current) === false) {
            throw new RuntimeException('Hearing session not found');
        }

        $now          = new DateTimeImmutable();
        $scheduledRaw = (string) ($current['scheduledDate'] ?? '');
        $scheduled    = $now;
        if ($scheduledRaw !== '') {
            $scheduled = $this->parseDateTime(value: $scheduledRaw);
        }

        $freezeAt = $scheduled->modify(
            '+'.self::ATTENDANCE_GRACE_HOURS.' hour'
        );
        $isFrozen = $now > $freezeAt;

        $existing = (array) ($current['attendance'] ?? []);
        $merged   = $existing;
        $audit    = (array) ($current['auditTrail'] ?? []);

        foreach ($entries as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            if ($isFrozen === true) {
                $hasReason = isset($entry['correctionReason'])
                    && trim((string) $entry['correctionReason']) !== '';
                if ($hasReason === false) {
                    throw new RuntimeException(
                        'Aanwezigheidscorrectie vereist toelichting in audit trail'
                    );
                }

                $audit = $this->appendAudit(
                    existing: $audit,
                    event: 'attendance-late-correction',
                    tag: self::TAG_VERSLAG,
                    payload: [
                        'invitee'          => (string) ($entry['invitee'] ?? ''),
                        'present'          => (bool) ($entry['present'] ?? false),
                        'correctionReason' => (string) $entry['correctionReason'],
                    ],
                );
            }

            $merged[] = $entry;
        }//end foreach

        $update = [
            'attendance'         => $merged,
            'attendanceFrozenAt' => $freezeAt->format(DateTimeInterface::ATOM),
            'auditTrail'         => $audit,
        ];

        try {
            return $objectService->saveObject(
                $register,
                $schema,
                $update,
                $sessionId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest hearing: failed to record attendance: '.$e->getMessage()
            );
            throw new RuntimeException('Could not record attendance');
        }
    }//end recordAttendance()

    /**
     * Attach minutes (verslag) to a hearingSession and promote it to
     * uitgevoerd when at least one of minutesSummary or minutesDocument
     * is provided (REQ-BH-6). When audioRecording is supplied it SHALL
     * only be accepted if recordingConsent = granted.
     *
     * @param string               $sessionId UUID of the hearingSession
     * @param array<string, mixed> $payload   Minutes payload {minutesSummary?, minutesDocument?, audioRecording?, recordingConsent?}
     *
     * @return array<string, mixed> The updated hearingSession record
     *
     * @throws RuntimeException When verslag is missing, audio consent is
     *                          denied, or persistence fails.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function addMinutes(
        string $sessionId,
        array $payload
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(
            key: 'hearing_session_schema'
        );

        $current = $objectService->find($sessionId, register: $register, schema: $schema);
        if (is_array($current) === false) {
            throw new RuntimeException('Hearing session not found');
        }

        $summary  = (string) (
            $payload['minutesSummary'] ?? ($current['minutesSummary'] ?? '')
        );
        $document = (string) (
            $payload['minutesDocument'] ?? ($current['minutesDocument'] ?? '')
        );

        if (trim($summary) === '' && trim($document) === '') {
            throw new RuntimeException(
                'Verslag (art. 7:7) ontbreekt — vul minutesSummary of upload minutesDocument'
            );
        }

        $audit = (array) ($current['auditTrail'] ?? []);

        // Audio recording handling: gated by explicit consent.
        if (isset($payload['audioRecording']) === true
            && (string) $payload['audioRecording'] !== ''
        ) {
            $consent = (string) (
                $payload['recordingConsent'] ?? ($current['recordingConsent'] ?? 'not_requested')
            );
            if ($consent !== 'granted') {
                $audit = $this->appendAudit(
                    existing: $audit,
                    event: 'audio-upload-denied',
                    tag: self::TAG_RECORDING_CONSENT,
                    payload: [
                        'consent' => $consent,
                    ],
                );

                try {
                    $objectService->saveObject(
                        $register,
                        $schema,
                        ['auditTrail' => $audit],
                        $sessionId
                    );
                } catch (\Throwable $auditError) {
                    $this->logger->error(
                        'Procest hearing: failed to log audio-denial: '
                        .$auditError->getMessage()
                    );
                }

                throw new RuntimeException(
                    'Bezwaarmaker heeft geen toestemming gegeven voor audio-opname'
                );
            }//end if
        }//end if

        $minutesSummary = null;
        if ($summary !== '') {
            $minutesSummary = $summary;
        }

        $minutesDocument = null;
        if ($document !== '') {
            $minutesDocument = $document;
        }

        $update = [
            'minutesSummary'  => $minutesSummary,
            'minutesDocument' => $minutesDocument,
            'status'          => 'uitgevoerd',
        ];

        if (isset($payload['audioRecording']) === true
            && (string) $payload['audioRecording'] !== ''
        ) {
            $update['audioRecording'] = (string) $payload['audioRecording'];
        }

        if (isset($payload['recordingConsent']) === true) {
            $update['recordingConsent'] = (string) $payload['recordingConsent'];
        }

        $update['auditTrail'] = $this->appendAudit(
            existing: $audit,
            event: 'verslag-recorded',
            tag: self::TAG_VERSLAG,
            payload: [
                'hasSummary'  => trim($summary) !== '',
                'hasDocument' => trim($document) !== '',
            ],
        );

        try {
            return $objectService->saveObject(
                $register,
                $schema,
                $update,
                $sessionId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest hearing: failed to add minutes: '.$e->getMessage()
            );
            throw new RuntimeException('Could not add minutes');
        }
    }//end addMinutes()

    /**
     * Listener entry-point: seed a default hearing session for a bezwaar
     * that has just transitioned to "Hoorzitting gepland" (REQ-BH-2
     * scheduling). The seed is intentionally minimal — invitees are
     * empty, scheduledDate is fourteen days out — and only fires when
     * no hearing session already exists for the case.
     *
     * @param string $bezwaarId The bezwaar (lifecycle) UUID
     *
     * @return array<string, mixed>|null Created hearing session, or null when one already exists / infra unavailable.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function seedDefaultHearing(string $bezwaarId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(
            key: 'hearing_session_schema'
        );

        if ($register === '' || $schema === '') {
            return null;
        }

        $caseId = $this->resolveCaseIdFromBezwaar(bezwaarId: $bezwaarId);
        if ($caseId === '') {
            return null;
        }

        try {
            $existing = $objectService->findObjects(
                $register,
                $schema,
                ['case' => $caseId]
            );
            if (is_array($existing) === true && $existing !== []) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Procest hearing: lookup for existing sessions failed: '
                .$e->getMessage()
            );
            return null;
        }

        $scheduled = (new DateTimeImmutable())
            ->modify('+14 days')
            ->setTime(10, 0, 0);

        try {
            return $this->schedule(
                caseId: $caseId,
                scheduledDate: $scheduled->format(DateTimeInterface::ATOM),
                chairpersonId: 'system',
                invitees: [
                    [
                        'role'               => 'bezwaarmaker',
                        'channel'            => 'email',
                        'accessibilityNeeds' => [],
                    ],
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->info(
                'Procest hearing: seedDefaultHearing skipped for bezwaar '
                .$bezwaarId.': '.$e->getMessage()
            );
            return null;
        }
    }//end seedDefaultHearing()

    /**
     * Append an entry to the hearingSession auditTrail with a legal
     * tag drawn from REQ-BH-8.
     *
     * @param array<int, array<string, mixed>> $existing Existing audit entries
     * @param string                           $event    Event slug
     * @param string                           $tag      Awb / AVG tag
     * @param array<string, mixed>             $payload  Structured payload
     *
     * @return array<int, array<string, mixed>>
     */
    private function appendAudit(
        array $existing,
        string $event,
        string $tag,
        array $payload
    ): array {
        $entry = [
            'event'   => $event,
            'tag'     => $tag,
            'actor'   => $this->resolveUserId(),
            'at'      => (new DateTimeImmutable())
                ->format(DateTimeInterface::ATOM),
            'payload' => $payload,
        ];

        $existing[] = $entry;
        return $existing;
    }//end appendAudit()

    /**
     * Resolve the acting user UID from IUserSession.
     *
     * @return string
     */
    private function resolveUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'system';
        }

        return $user->getUID();
    }//end resolveUserId()

    /**
     * Parse an ISO-8601 date-time string into an immutable date.
     *
     * @param string $value Date-time string
     *
     * @return \DateTimeImmutable
     *
     * @throws RuntimeException When the value cannot be parsed.
     */
    private function parseDateTime(string $value): \DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Invalid scheduledDate: '.$value
            );
        }
    }//end parseDateTime()

    /**
     * Parse an ISO-8601 date (Y-m-d) string into an immutable date.
     *
     * @param string $value Date string
     *
     * @return \DateTimeImmutable
     */
    private function parseDate(string $value): \DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return new DateTimeImmutable();
        }
    }//end parseDate()

    /**
     * Compute the Awb art. 7:4 lid 2 inspection deadline as
     * scheduledDate − INSPECTION_FLOOR_DAYS.
     *
     * @param \DateTimeImmutable $scheduled Hearing date
     *
     * @return \DateTimeImmutable
     */
    private function computeInspectionDeadline(
        \DateTimeImmutable $scheduled
    ): \DateTimeImmutable {
        return $scheduled->modify(
            '-'.self::INSPECTION_FLOOR_DAYS.' days'
        );
    }//end computeInspectionDeadline()

    /**
     * Block scheduling/rescheduling that would violate the 7-day
     * inspection floor (Awb art. 7:4 lid 2).
     *
     * @param \DateTimeImmutable $scheduled Hearing date
     * @param \DateTimeImmutable $today     Current date
     *
     * @return void
     *
     * @throws RuntimeException When the floor is breached.
     */
    private function guardInspectionFloor(
        \DateTimeImmutable $scheduled,
        \DateTimeImmutable $today
    ): void {
        $minDate = $today->modify(
            '+'.self::INSPECTION_FLOOR_DAYS.' days'
        );

        if ($scheduled < $minDate) {
            throw new RuntimeException(
                'Inzagetermijn (art. 7:4) wordt geschonden — minimaal 7 dagen voor de hoorzitting'
            );
        }
    }//end guardInspectionFloor()

    /**
     * Stamp each invitee with an invitedAt timestamp when missing so
     * downstream consumers have a chain-of-custody marker for REQ-BH-8.
     *
     * @param array<int, mixed>  $invitees Raw invitee entries
     * @param \DateTimeImmutable $when     Timestamp to apply
     *
     * @return array<int, array<string, mixed>>
     */
    private function stampInvitees(
        array $invitees,
        \DateTimeImmutable $when
    ): array {
        $stamped = [];
        foreach ($invitees as $invitee) {
            if (is_array($invitee) === false) {
                continue;
            }

            if (isset($invitee['invitedAt']) === false
                || (string) $invitee['invitedAt'] === ''
            ) {
                $invitee['invitedAt'] = $when->format(
                    DateTimeInterface::ATOM
                );
            }

            $stamped[] = $invitee;
        }

        return $stamped;
    }//end stampInvitees()

    /**
     * Resolve the underlying procest case UUID from a bezwaar
     * (lifecycle) UUID. Falls back to the input when bezwaar_schema is
     * not configured so the listener can still seed against case-keyed
     * inputs.
     *
     * @param string $bezwaarId Bezwaar UUID
     *
     * @return string The resolved case UUID, or empty when unresolvable.
     */
    private function resolveCaseIdFromBezwaar(string $bezwaarId): string
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $bezwaarId;
        }

        $register      = $this->settingsService->getConfigValue(key: 'register');
        $bezwaarSchema = $this->settingsService->getConfigValue(
            key: 'bezwaar_schema'
        );

        if ($register === '' || $bezwaarSchema === '') {
            return $bezwaarId;
        }

        try {
            $bezwaar = $objectService->find($bezwaarId, register: $register, schema: $bezwaarSchema);
            if (is_array($bezwaar) === true) {
                $candidate = (string) ($bezwaar['case'] ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Procest hearing: bezwaar lookup failed: '.$e->getMessage()
            );
        }

        return $bezwaarId;
    }//end resolveCaseIdFromBezwaar()
}//end class
