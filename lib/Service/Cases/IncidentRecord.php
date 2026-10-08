<?php

/**
 * An incident on a case: a dated event with its own owner.
 *
 * 🔑 AN INCIDENT IS NOT A SUB-CASE (D-4). A deelzaak has its own number, its
 * own term and its own decision; an incident has none of those. One address
 * generates a report in March, another in June and a third in September, and
 * the pattern is what makes the case worth keeping open. Making each report a
 * deelzaak would give it a beslistermijn it does not have.
 *
 * 🔴 THE ORDER IS THE EVENT DATE, NOT THE RECORDING MOMENT (D-6). A report
 * recorded three weeks late belongs where it HAPPENED. Sorting by creation
 * would put it at the top of the list and quietly rewrite the sequence the
 * case is about, and the delay itself is a fact worth showing rather than
 * hiding.
 *
 * 🔴 AN INCIDENT OWNS ITS OWN HAND-OFF (D-5). The case sits with the area
 * handler while one report inside it is worked by an inspector, so moving an
 * incident must leave the case's own assignee exactly as it was. That is the
 * single rule this class exists to hold, and it is the one a careless
 * implementation breaks by reusing the case hand-off.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use Throwable;

/**
 * The rules an incident obeys: its order, its delay and its own hand-off.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentRecord {
	/**
	 * The states an incident moves through.
	 *
	 * Deliberately short. An incident is a report inside a case, not a case:
	 * a status machine of its own would be the deelzaak this must not become.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['open', 'in-behandeling', 'afgehandeld'];

	/**
	 * The states that still need somebody's attention.
	 *
	 * @var array<int, string>
	 */
	public const OPEN_STATES = ['open', 'in-behandeling'];

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one class that may resolve a time
	 *        zone. A delay in days is a difference between two CALENDAR days,
	 *        so it has to be counted in the zone the organisation administers;
	 *        counting it in a zone this class named itself is the zone nobody
	 *        chose (openspec/changes/one-date-write-path).
	 */
	public function __construct(private readonly CaseDateNormaliser $dates) {
	}//end __construct()

	/**
	 * The incidents of one case, oldest event first.
	 *
	 * @param array<int, array<string, mixed>> $incidents The stored incidents.
	 *
	 * @return array<int, array<string, mixed>> The incidents, in event order.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function inEventOrder(array $incidents): array {
		$rows = array_values(array_filter($incidents, static fn ($row): bool => is_array($row)));

		usort(
			$rows,
			function (array $left, array $right): int {
				$byDate = ($this->momentOf(row: $left, field: 'eventDate') <=> $this->momentOf(row: $right, field: 'eventDate'));
				if ($byDate !== 0) {
					return $byDate;
				}

				// Two reports of one day keep the order they were recorded in,
				// which is the only order anybody can check afterwards.
				return ($this->momentOf(row: $left, field: 'recordedAt') <=> $this->momentOf(row: $right, field: 'recordedAt'));
			}
		);

		return $rows;
	}//end inEventOrder()

	/**
	 * How many days passed between the event and its recording.
	 *
	 * @param array<string, mixed> $incident One incident.
	 *
	 * @return int|null The delay in days, or null when either moment is missing.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function recordingDelayDays(array $incident): ?int {
		$event = $this->parse(value: (string)($incident['eventDate'] ?? ''));
		$recorded = $this->parse(value: (string)($incident['recordedAt'] ?? ''));
		if ($event === null || $recorded === null) {
			return null;
		}

		// Both moments are read in ONE zone before the days are counted. A
		// report whose event date was written as a bare date (parsed in the
		// server zone) and whose recording carries +02:00 is otherwise 22
		// hours short of a whole day, and the delay comes out one day low with
		// nothing to show why.
		$zone = $this->dates->timeZone();
		$days = (int)$event->setTimezone($zone)->setTime(0, 0)
			->diff($recorded->setTimezone($zone)->setTime(0, 0))->days;

		// A recording BEFORE the event is a typo in the event date, not a
		// negative delay. Reported as no delay rather than as a number that
		// reads like a prediction.
		if ($recorded < $event) {
			return 0;
		}

		return $days;
	}//end recordingDelayDays()

	/**
	 * How many incidents of a case still need attention.
	 *
	 * @param array<int, array<string, mixed>> $incidents The stored incidents.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function openCount(array $incidents): int {
		$open = 0;
		foreach ($incidents as $incident) {
			if (is_array($incident) === false) {
				continue;
			}

			// An incident with no state at all is OPEN: it was recorded and
			// nobody has said otherwise, and counting it as finished would let
			// a work list report a clean desk that is not.
			$state = trim((string)($incident['state'] ?? ''));
			if ($state === '' || in_array($state, self::OPEN_STATES, true) === true) {
				$open++;
			}
		}

		return $open;
	}//end openCount()

	/**
	 * The change a hand-off writes, which touches the incident alone.
	 *
	 * @param array<string, mixed> $incident The incident being handed over.
	 * @param string $assignee Who takes it.
	 *
	 * @return array<string, mixed> The fields to write on the INCIDENT.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function handoverChange(array $incident, string $assignee): array {
		$change = ['assignee' => trim($assignee)];

		// Taking an incident on is what starts work on it, so an untouched
		// state follows the hand-off. A state somebody has already moved on is
		// left alone: the inspector who closed it does not reopen it by
		// handing it to a colleague.
		$state = trim((string)($incident['state'] ?? ''));
		if ($state === '' || $state === 'open') {
			$change['state'] = 'in-behandeling';
		}

		return $change;
	}//end handoverChange()

	/**
	 * One field of a row as a sortable moment.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $field The field.
	 *
	 * @return int The timestamp, PHP_INT_MAX when the field cannot be read.
	 */
	private function momentOf(array $row, string $field): int {
		$moment = $this->parse(value: (string)($row[$field] ?? ''));

		// An unreadable date sorts LAST rather than first: an incident nobody
		// dated must not silently head the sequence the case is about.
		if ($moment === null) {
			return PHP_INT_MAX;
		}

		return $moment->getTimestamp();
	}//end momentOf()

	/**
	 * One value as a moment, or null.
	 *
	 * @param string $value The stored value.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function parse(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parse()
}//end class
