<?php

/**
 * The work of a colleague who is away.
 *
 * Every item is marked as covered and names whose it is. That marking is not
 * decoration: a handler who cannot tell their own fourteen cases from the
 * eleven they are standing in for this week has a longer list, not a clearer
 * one, and will close the wrong thing on a Friday afternoon.
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

use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCA\Dossiq\Service\SubstitutionService;
use OCP\IL10N;

/**
 * Cases and tasks routed to the reader by an active substitution.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class CoveredWorkSource implements QueueSource {
	/**
	 * Constructor.
	 *
	 * @param SubstitutionService $substitutions Who is away and who covers them.
	 * @param IL10N               $l10n          Translations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly SubstitutionService $substitutions,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The source's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function name(): string {
		return 'covered-work';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Work you are covering');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('Covered work leaves when your colleague is back, or when the work is done.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return ['notification:substitutionRegisteredForSubstitute'];
	}//end mechanisms()

	/**
	 * The work routed to this person while somebody else is away.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$work = $this->substitutions->getSubstitutedWorkFor(userId: $userId);

		$items = [];
		foreach ($this->rowsOf(work: $work, key: 'cases') as $row) {
			$item = $this->itemOf(row: $row, subjectType: 'case', route: 'CaseDetail', dateKey: 'deadline');
			if ($item !== null) {
				$items[] = $item;
			}
		}

		foreach ($this->rowsOf(work: $work, key: 'tasks') as $row) {
			$item = $this->itemOf(row: $row, subjectType: 'task', route: 'TaskDetail', dateKey: 'dueAt');
			if ($item !== null) {
				$items[] = $item;
			}
		}

		return $items;
	}//end itemsFor()

	/**
	 * One half of the resolver's answer.
	 *
	 * @param array<string, mixed> $work The resolver's answer.
	 * @param string               $key  Either cases or tasks.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function rowsOf(array $work, string $key): array {
		$rows = ($work[$key] ?? []);

		return (is_array($rows) === true ? $rows : []);
	}//end rowsOf()

	/**
	 * One covered row as a queue item.
	 *
	 * A row with no `_substituted` block is dropped rather than listed
	 * unmarked. An unmarked covered item reads as the reader's own work, and
	 * acting on somebody else's case believing it is yours is the failure this
	 * whole source exists to avoid.
	 *
	 * @param array<string, mixed> $row         The row.
	 * @param string               $subjectType What the item points at.
	 * @param string               $route       The route name that opens it.
	 * @param string               $dateKey     The property holding its date.
	 *
	 * @return QueueItem|null The item, or null when the row cannot be marked.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function itemOf(array $row, string $subjectType, string $route, string $dateKey): ?QueueItem {
		$id = trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
		$context = ($row['_substituted'] ?? []);
		$absentee = '';
		if (is_array($context) === true) {
			$absentee = trim((string)($context['absentee'] ?? ''));
		}

		if ($id === '' || $absentee === '') {
			return null;
		}

		$due = $row[$dateKey] ?? null;

		return new QueueItem(
			source: $this->name(),
			subjectType: $subjectType,
			subjectId: $id,
			title: (string)($row['title'] ?? $id),
			priority: (string)($row['priority'] ?? ''),
			dueAt: ((is_string($due) === true && trim($due) !== '') ? trim($due) : null),
			coveredFor: $absentee,
			route: ['name' => $route, 'params' => ['id' => $id]]
		);
	}//end itemOf()
}//end class
