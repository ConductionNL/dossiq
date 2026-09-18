<?php

/**
 * The next action planned for this person on a case (gap register row 3.28).
 *
 * WHY IT IS ON THE QUEUE AT ALL. A planned action with an owner and a date is
 * work waiting on a named person, which is the one thing this queue is for. It
 * was the only such mechanism not represented here, so the case said what
 * happened next and the person who had to do it did not find out until they
 * opened the case.
 *
 * WHY IT IS ITS OWN SOURCE AND NOT A ROW IN `EngineTaskSource`. The queue
 * groups by mechanism on purpose, and what closes one of these is not what
 * closes a task: an engine task closes when somebody completes it, and a
 * planned action closes when somebody completes it AND the chain moves on.
 * `closesWhen()` says so, which is the sentence the group header carries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCP\IL10N;

/**
 * Planned actions owned by the reader.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */
class PlannedActionSource implements QueueSource {

	/**
	 * Constructor.
	 *
	 * @param PlannedActionService $plannedActions Reads planned actions.
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(
		private readonly PlannedActionService $plannedActions,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The source's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function name(): string {
		return 'planned-actions';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Planned on a case');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t(
			'A planned action leaves when you complete it, which plans whatever the case type says comes after it.'
		);
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function mechanisms(): array {
		return [];
	}//end mechanisms()

	/**
	 * The actions planned for this person.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function itemsFor(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$items = [];
		foreach ($this->plannedActions->ownedBy(userId: $userId) as $action) {
			$caseId = (string)($action['case'] ?? '');
			$label = trim((string)($action['label'] ?? ''));
			if ($label === '') {
				$label = trim((string)($action['actionType'] ?? ''));
			}

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'plannedAction',
				subjectId: (string)($action['id'] ?? ($action['uuid'] ?? '')),
				title: $label,
				priority: '',
				dueAt: (((string)($action['plannedFor'] ?? '')) !== ''
					? substr((string)$action['plannedFor'], 0, 10)
					: null),
				coveredFor: null,
				// The route goes to the CASE and not to the action. There is no
				// planned-action page, and the thing a person needs in order to
				// do the action is the case it is on.
				route: ($caseId === ''
					? ['name' => 'PersonalQueue']
					: ['name' => 'CaseDetail', 'params' => ['id' => $caseId]])
			);
		}

		return $items;
	}//end itemsFor()
}//end class
