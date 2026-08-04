<?php

/**
 * Procest Bezwaar Hearing Schedule Planner.
 *
 * The Awb art. 7:4 lid 2 date arithmetic behind scheduling a hoorzitting.
 * Split out of HearingService so that service keeps only the persistence
 * orchestration: everything that answers "when may this hearing be held,
 * and from when must the file be open for inspection" — parsing the
 * caller's date strings, deriving the inspection deadline as
 * scheduledDate − 7 days, clamping inspectionAvailableFrom to that
 * deadline, refusing a date that breaches the seven-day floor, and
 * stamping each invitee with its chain-of-custody invitedAt marker —
 * lives here and nowhere else.
 *
 * The class is deliberately dependency-free: it is pure date/array
 * arithmetic and can be exercised without any Nextcloud or OpenRegister
 * infrastructure.
 *
 * @category Service
 * @package  OCA\Procest\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * Computes hearing dates, the inspection-of-file floor, and invitee stamps.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class HearingSchedulePlanner
{

    /**
     * Awb art. 7:4 lid 2 inspection-of-file floor in days.
     */
    public const INSPECTION_FLOOR_DAYS = 7;

    /**
     * Parse an ISO-8601 date-time string into an immutable date.
     *
     * @param string $value Date-time string.
     *
     * @return DateTimeImmutable The parsed date-time.
     *
     * @throws RuntimeException When the value cannot be parsed.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function parseDateTime(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid scheduledDate: '.$value);
        }
    }//end parseDateTime()

    /**
     * Parse an ISO-8601 date (Y-m-d) string into an immutable date,
     * falling back to "now" when the value is unusable.
     *
     * @param string $value Date string.
     *
     * @return DateTimeImmutable The parsed date, or the current date-time.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function parseDate(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return new DateTimeImmutable();
        }
    }//end parseDate()

    /**
     * Compute the Awb art. 7:4 lid 2 inspection deadline as
     * scheduledDate − INSPECTION_FLOOR_DAYS.
     *
     * @param DateTimeImmutable $scheduled Hearing date.
     *
     * @return DateTimeImmutable The inspection deadline.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function computeInspectionDeadline(DateTimeImmutable $scheduled): DateTimeImmutable
    {
        return $scheduled->modify('-'.self::INSPECTION_FLOOR_DAYS.' days');
    }//end computeInspectionDeadline()

    /**
     * Resolve the inspectionAvailableFrom date, clamped to the
     * inspection deadline (design.md: available <= deadline).
     *
     * @param array<string, mixed> $payload  Optional schedule extras.
     * @param DateTimeImmutable    $deadline Computed inspection deadline.
     * @param DateTimeImmutable    $now      Current date-time.
     *
     * @return DateTimeImmutable The resolved availability date.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function resolveAvailableFrom(
        array $payload,
        DateTimeImmutable $deadline,
        DateTimeImmutable $now
    ): DateTimeImmutable {
        $available = $now->setTime(0, 0, 0);
        if (isset($payload['inspectionAvailableFrom']) === true) {
            $available = $this->parseDate(value: (string) $payload['inspectionAvailableFrom']);
        }

        if ($available > $deadline) {
            // Per design.md: inspectionAvailableFrom must be <= inspectionDeadline.
            return $deadline;
        }

        return $available;
    }//end resolveAvailableFrom()

    /**
     * Block scheduling/rescheduling that would violate the 7-day
     * inspection floor (Awb art. 7:4 lid 2).
     *
     * @param DateTimeImmutable $scheduled Hearing date.
     * @param DateTimeImmutable $today     Current date.
     *
     * @return void
     *
     * @throws RuntimeException When the floor is breached.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function guardInspectionFloor(DateTimeImmutable $scheduled, DateTimeImmutable $today): void
    {
        $minDate = $today->modify('+'.self::INSPECTION_FLOOR_DAYS.' days');

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
     * @param array<int, mixed> $invitees Raw invitee entries.
     * @param DateTimeImmutable $when     Timestamp to apply.
     *
     * @return array<int, array<string, mixed>> The stamped invitees.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function stampInvitees(array $invitees, DateTimeImmutable $when): array
    {
        $stamped = [];
        foreach ($invitees as $invitee) {
            if (is_array($invitee) === false) {
                continue;
            }

            if (isset($invitee['invitedAt']) === false
                || (string) $invitee['invitedAt'] === ''
            ) {
                $invitee['invitedAt'] = $when->format(DateTimeInterface::ATOM);
            }

            $stamped[] = $invitee;
        }

        return $stamped;
    }//end stampInvitees()
}//end class
