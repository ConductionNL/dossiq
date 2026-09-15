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

use OCA\Dossiq\Service\Queue\QueueItem;
use OCP\IL10N;
use OCA\Dossiq\Service\SettingsService;

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
				route: ['name' => 'CaseDetail', 'params' => ['id' => $id]]
			);
		}

		return $items;
	}//end itemsFor()
}//end class
