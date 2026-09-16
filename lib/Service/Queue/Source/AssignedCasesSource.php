<?php

/**
 * The cases that are yours.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue\Source
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCP\IL10N;
use Throwable;

/**
 * Open cases assigned to the reader.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class AssignedCasesSource extends RegisterBackedSource {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration.
	 * @param IL10N           $l10n     Translations, because the label is on screen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		SettingsService $settings,
		private readonly IL10N $l10n,
	) {
		parent::__construct(settings: $settings);
	}//end __construct()

	/**
	 * The source's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function name(): string {
		return 'assigned-cases';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Cases assigned to you');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A case leaves when it is closed or goes to somebody else.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return ['notification:caseAssigned', 'notification:caseHandoffIntake', 'flow:DossiqTxSetFieldNode'];
	}//end mechanisms()

	/**
	 * The open cases assigned to this person.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$schema = trim((string)$this->settings->getConfigValue('case_schema', 'case'));
		if ($schema === '') {
			$schema = 'case';
		}

		$rows = $this->rows(
			schema: $schema,
			filters: ['assignee' => $userId, 'isFinalStatus' => false]
		);

		$items = [];
		foreach ($rows as $row) {
			$id = $this->idOf(row: $row);
			if ($id === '') {
				continue;
			}

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'case',
				subjectId: $id,
				title: (string)($row['title'] ?? $id),
				priority: (string)($row['priority'] ?? ''),
				dueAt: $this->dateOf(row: $row, key: 'deadline'),
				coveredFor: null,
				route: ['name' => 'CaseDetail', 'params' => ['id' => $id]],
				waiting: $this->waitingFactsOf(row: $row)
			);
		}

		return $items;
	}//end itemsFor()

	/**
	 * Who this case is waiting on, since when, and how often it has been chased.
	 *
	 * 🔴 THE DAYS ARE COUNTED HERE, not in the browser. Every other number the
	 * queue renders was computed server-side against the organisation's
	 * calendar, and a count computed two ways drifts the first time one of them
	 * is fixed.
	 *
	 * A case nobody is waiting on answers an empty array rather than a row of
	 * zeroes, because `waiting on us for 0 days, chased 0 times` is a sentence
	 * that would sit under every case in the queue and say nothing.
	 *
	 * @param array<string, mixed>   $row The case row.
	 * @param DateTimeImmutable|null $now The moment to count from, for the tests.
	 *
	 * @return array{on: string, since: string, days: int, chases: int}|array{} The facts, empty when none.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function waitingFactsOf(array $row, ?DateTimeImmutable $now = null): array {
		$on = trim((string)($row['pauseWaitingOn'] ?? ''));
		if ($on === '' || $on === StatusDeclaration::WAITING_ON_US) {
			return [];
		}

		// `waitingSince` is written by the pause; `waitingOnApplicantSince` is
		// what the aanvullingsverzoek wrote before pause reasons existed. The
		// older one is the fallback so a case paused last week still reads a
		// number rather than a zero.
		$since = trim((string)($row['waitingSince'] ?? ''));
		if ($since === '') {
			$since = trim((string)($row['waitingOnApplicantSince'] ?? ''));
		}

		return [
			'on' => $on,
			'since' => $since,
			'days' => $this->daysSince(since: $since, now: ($now ?? new DateTimeImmutable())),
			'chases' => max(0, (int)($row['chasesSent'] ?? 0)),
		];
	}//end waitingFactsOf()

	/**
	 * How many days have passed since a stored moment.
	 *
	 * @param string            $since The stored moment.
	 * @param DateTimeImmutable $now   The moment to count to.
	 *
	 * @return int The days, 0 when the moment cannot be read.
	 */
	private function daysSince(string $since, DateTimeImmutable $now): int {
		if ($since === '') {
			return 0;
		}

		try {
			$from = new DateTimeImmutable($since);
		} catch (Throwable) {
			return 0;
		}

		return (int)$from->setTime(0, 0)->diff($now->setTime(0, 0))->days;
	}//end daysSince()
}//end class
