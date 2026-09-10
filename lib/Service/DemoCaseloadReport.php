<?php

/**
 * Dossiq Demo Caseload Report
 *
 * Counts what the dashboard widgets will actually find, by reading the register
 * back.
 *
 * 🔴 THIS READS THE STORE, NEVER THE SEED FILE, AND THAT IS THE WHOLE POINT. The
 * seed asks for a deadline indirectly, by backdating `startDate` so OpenRegister
 * materialises the deadline it wants. Whether the deadline it materialised
 * actually landed in the intended bucket is a separate question, and a count
 * taken from the seed file would agree with the seed file by construction.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\IUserSession;
use RuntimeException;

/**
 * Reports the caseload buckets the dashboard reads.
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
class DemoCaseloadReport {
	/**
	 * Constructor.
	 *
	 * 🔴 `TERMINAL_TASK_STATUSES` USED TO LIVE HERE, a third copy of the
	 * same three state names. The engine owns the open/closed split now and
	 * is asked for it, so there is nothing left to keep in step with
	 * `Task::STATES`.
	 *
	 * @param DemoCaseloadGateway $gateway OpenRegister access.
	 * @param EngineTaskInbox $engineTasks The engine's task counter.
	 * @param IUserSession $userSession The identity the seed command set.
	 *
	 * @return void
	 */
	public function __construct(
		private DemoCaseloadGateway $gateway,
		private EngineTaskInbox $engineTasks,
		private IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Count the buckets the dashboard widgets read.
	 *
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return array{open: integer, overdue: integer, dueSoon: integer, closed: integer, tasksOpen: integer, tasksDue: integer}
	 *         The bucket counts.
	 *
	 * @throws RuntimeException When OpenRegister or the configuration is missing.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function buckets(?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable('today'));
		$objectService = $this->gateway->objectService();
		$ids = $this->gateway->schemaIds();

		$today = $now->format('Y-m-d');
		$horizon = $now->modify('+3 days')->format('Y-m-d');

		$cases = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['case'],
			filters: []
		);

		return ($this->caseBuckets(cases: $cases, today: $today, horizon: $horizon)
			+ $this->taskBuckets(horizon: $horizon));
	}//end buckets()

	/**
	 * Count the case buckets.
	 *
	 * @param array<int, mixed> $cases The case rows.
	 * @param string $today Today, as Y-m-d.
	 * @param string $horizon Three days out, as Y-m-d.
	 *
	 * @return array{open: integer, overdue: integer, dueSoon: integer, closed: integer} The counts.
	 */
	private function caseBuckets(array $cases, string $today, string $horizon): array {
		$counts = ['open' => 0, 'overdue' => 0, 'dueSoon' => 0, 'closed' => 0];

		foreach ($cases as $case) {
			$row = $this->gateway->toArray(object: $case);

			if (($row['isFinalStatus'] ?? false) === true) {
				$counts['closed']++;
				continue;
			}

			$counts['open']++;

			$deadline = substr((string)($row['deadline'] ?? ''), 0, 10);
			if ($deadline === '') {
				continue;
			}

			if ($deadline < $today) {
				$counts['overdue']++;
				continue;
			}

			if ($deadline <= $horizon) {
				$counts['dueSoon']++;
			}
		}//end foreach

		return $counts;
	}//end caseBuckets()

	/**
	 * Count the task buckets, both of them server-side.
	 *
	 * The horizon is a `dueBefore` on the ENGINE, not a string compare over
	 * every row dossiq happened to fetch. That matters beyond tidiness: the
	 * old read pulled a page and counted it, so past the page boundary the
	 * seed command's read-back would have under-reported and failed a seed
	 * that had in fact worked.
	 *
	 * @param string $horizon Three days out, as Y-m-d.
	 *
	 * @return array{tasksOpen: integer, tasksDue: integer} The counts.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	private function taskBuckets(string $horizon): array {
		$actor = ($this->userSession->getUser()?->getUID() ?? '');

		return [
			'tasksOpen' => $this->engineTasks->countOpenEverywhere(actor: $actor),
			'tasksDue' => $this->engineTasks->countOpenEverywhere(
				actor: $actor,
				dueBefore: ($horizon . 'T23:59:59+00:00')
			),
		];
	}//end taskBuckets()
}//end class
