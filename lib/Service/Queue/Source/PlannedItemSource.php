<?php

/**
 * The things this person put on their own day.
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

use OCA\Dossiq\Service\Queue\PersonalAgendaItemService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCP\IL10N;

/**
 * Planned agenda items with no case behind them.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class PlannedItemSource implements QueueSource {
	/**
	 * Constructor.
	 *
	 * @param PersonalAgendaItemService $agenda The person's own calendar.
	 * @param IL10N                     $l10n   Translations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly PersonalAgendaItemService $agenda,
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
		return 'planned-items';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Planned by you');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A planned item leaves when its moment has passed, or when you delete it.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return [];
	}//end mechanisms()

	/**
	 * The items this person planned for themselves.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$items = [];
		foreach ($this->agenda->upcomingFor(userId: $userId) as $planned) {
			$startsAt = trim((string)$planned['startsAt']);

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'plannedItem',
				subjectId: (string)$planned['uid'],
				title: (string)$planned['title'],
				priority: '',
				dueAt: ($startsAt === '' ? null : substr($startsAt, 0, 10)),
				coveredFor: null,
				route: ['name' => 'PersonalQueue']
			);
		}

		return $items;
	}//end itemsFor()
}//end class
