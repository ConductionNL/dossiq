<?php

/**
 * The tasks the engine is holding for the reader.
 *
 * This is the one mechanism dossiq's My Work tile already showed, and it stays
 * exactly where it was: OpenRegister owns the task engine and dossiq reads its
 * inbox. What changes is that it is now one source among eight rather than the
 * whole page.
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
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\IL10N;
use RuntimeException;

/**
 * Open engine tasks assigned to the reader.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class EngineTaskSource implements QueueSource {
	/**
	 * Constructor.
	 *
	 * @param EngineTaskInbox $inbox The engine's inbox reader.
	 * @param IL10N           $l10n  Translations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly EngineTaskInbox $inbox,
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
		return 'tasks';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Your open tasks');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A task leaves when it is completed or cancelled.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return [
			'flow:DossiqAskPersonNode',
			'flow:DossiqTxCreateTaskNode',
			'flow:DossiqScheduleReminderNode',
			'flow:DossiqNotifyRoleNode',
		];
	}//end mechanisms()

	/**
	 * The open tasks waiting on this person.
	 *
	 * The inbox answers an empty list and records `lastError()` rather than
	 * throwing, which is right for a tile and wrong for a queue: a tile that
	 * shows nothing is one tile, and a queue that shows nothing is a claim
	 * about the reader's whole day. So the error is read back and raised.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @throws RuntimeException When the engine could not be read.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$rows = $this->inbox->openForAssignee(actor: $userId);

		$failure = trim($this->inbox->lastError());
		if ($failure !== '') {
			throw new RuntimeException($failure);
		}

		$items = [];
		foreach ($rows as $row) {
			$id = trim((string)($row['uuid'] ?? ($row['id'] ?? '')));
			if ($id === '') {
				continue;
			}

			$raw = ($row['dueAt'] ?? ($row['dueDate'] ?? null));
			$due = null;
			if (is_string($raw) === true && trim($raw) !== '') {
				$due = trim($raw);
			}

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'task',
				subjectId: $id,
				title: (string)($row['title'] ?? ($row['name'] ?? $id)),
				priority: (string)($row['priority'] ?? ''),
				dueAt: $due,
				coveredFor: null,
				route: ['name' => 'TaskDetail', 'params' => ['id' => $id]]
			);
		}

		return $items;
	}//end itemsFor()
}//end class
