<?php

/**
 * The cases sitting on a team the reader holds the seat on.
 *
 * WHAT A SEAT IS IN DOSSIQ. The proposal calls this the coordinator seat. A
 * dossiq case has no coordinator property: it has `assignedGroup`, the team
 * the work landed on, and `assignee`, the person who picked it up. The seat is
 * therefore the team, and holding it means being in that group. Inventing a
 * coordinator field to match the wording would have put a second owner on the
 * case, which is exactly the two-statuses problem D-6 refuses elsewhere in
 * this change.
 *
 * Only cases NOBODY has picked up are listed. A case a colleague is already on
 * is their item, not the whole team's, and a queue that lists it for eight
 * people is a queue eight people learn to skim.
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
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Unclaimed cases on the reader's own teams.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class CoordinatorSeatSource extends RegisterBackedSource {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration.
	 * @param IL10N           $l10n     Translations.
	 * @param IGroupManager   $groups   The reader's groups, which are the seats they hold.
	 * @param IUserManager    $users    Resolves the reader.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		SettingsService $settings,
		private readonly IL10N $l10n,
		private readonly IGroupManager $groups,
		private readonly IUserManager $users,
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
		return 'coordinator-seat';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Waiting on your team');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A case leaves when somebody takes it, or when it is closed.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return ['flow:DossiqTxSetStatusNode', 'flow:DossiqTxCreateSubCaseNode'];
	}//end mechanisms()

	/**
	 * The unclaimed cases on this person's teams.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$user = $this->users->get($userId);
		if ($user === null) {
			return [];
		}

		$seats = $this->groups->getUserGroupIds($user);
		if ($seats === []) {
			return [];
		}

		$schema = trim((string)$this->settings->getConfigValue('case_schema', 'case'));
		$schema = ($schema === '' ? 'case' : $schema);

		$items = [];
		foreach ($seats as $seat) {
			$rows = $this->rows(
				schema: $schema,
				filters: ['assignedGroup' => $seat, 'assignee' => 'IS NULL', 'isFinalStatus' => false]
			);

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
					route: ['name' => 'CaseDetail', 'params' => ['id' => $id]]
				);
			}
		}

		return $items;
	}//end itemsFor()
}//end class
