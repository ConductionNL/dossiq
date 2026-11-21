<?php

/**
 * What a planned calendar event says: when it starts and what its body reads.
 *
 * Split out of {@see \OCA\Dossiq\Service\HearingCalendarService}, which keeps
 * the calendar writes. This class writes nothing: it reads the start moment
 * from the stored data (logging when it cannot) and builds the description
 * lines, so the event built from them is the same for every participant.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Calendar
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
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Calendar;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
use Psr\Log\LoggerInterface;

/**
 * Reads a planned event's start and writes its description.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PlannedEventFields {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger, for a start that cannot be read.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the hearing's start moment, or nothing when the date is unreadable.
	 *
	 * An unreadable date must not become an event at "now": a hearing on the
	 * wrong day in someone's calendar is worse than no hearing in it.
	 *
	 * @param array<string, mixed> $data Hearing data.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function start(array $data): ?DateTimeImmutable {
		$date = (string)($data['date'] ?? '');
		try {
			return new DateTimeImmutable($date);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Hearing has no readable date, no calendar event written: ' . $date,
				['app' => AppIdentity::APP_ID],
			);
			return null;
		}
	}//end start()

	/**
	 * Describe the hearing in the body of the calendar event.
	 *
	 * The description names every planned participant, including the ones that
	 * are a bare name rather than an account: they cannot be invited, and the
	 * people who can be need to know they are expected too.
	 *
	 * @param array<string, mixed> $data Hearing data.
	 * @param array<int, mixed> $participants Participants as stored.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function description(array $data, array $participants): string {
		$lines = ['Hoorgesprek over klacht ' . (string)($data['complaint'] ?? '')];

		$type = (string)($data['type'] ?? '');
		if ($type !== '') {
			$lines[] = 'Vorm: ' . $type;
		}

		$talkUrl = (string)($data['talkRoomUrl'] ?? '');
		if ($talkUrl !== '') {
			$lines[] = 'Gespreksruimte: ' . $talkUrl;
		}

		$names = $this->participantNames(participants: $participants);
		if ($names !== []) {
			$lines[] = 'Deelnemers: ' . implode(', ', $names);
		}

		return implode("\n", $lines);
	}//end description()

	/**
	 * The trimmed, non-empty names of the participants stored as text.
	 *
	 * @param array<int, mixed> $participants Participants as stored.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function participantNames(array $participants): array {
		$names = [];
		foreach ($participants as $participant) {
			if (is_string($participant) === true && trim($participant) !== '') {
				$names[] = trim($participant);
			}
		}

		return $names;
	}//end participantNames()
}//end class
