<?php

/**
 * The cases the reader holds the coordinator seat on.
 *
 * READ THE SEAT, DO NOT APPROXIMATE IT. This source was first written against
 * `assignedGroup`, because a dossiq case had no coordinator and the team the
 * work landed on was the closest thing to one. It has one now:
 * `handing-a-case-over` landed `CaseSeats` while this change was being built,
 * and a coordinator is a role record against the case, findable by
 * `casesCoordinatedBy()`. Approximating a seat that exists would have listed
 * the wrong cases for every coordinator on the instance, and it would have
 * looked right.
 *
 * A coordinator is not the handler. `assigned-cases` already answers for the
 * cases in the reader's own hands; this one answers for the cases they are
 * accountable for and somebody else is doing, which is the second seat the
 * queue would otherwise have no way to show.
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

use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;

/**
 * Open cases the reader coordinates.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class CoordinatorSeatSource extends RegisterBackedSource {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration.
	 * @param IL10N           $l10n     Translations.
	 * @param CaseSeats       $seats    Who holds which seat on a case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		SettingsService $settings,
		private readonly IL10N $l10n,
		private readonly CaseSeats $seats,
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
		return $this->l10n->t('Cases you coordinate');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A case leaves when you hand the seat on, or when it is closed.');
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
	 * The open cases this person coordinates.
	 *
	 * The seat is read first and the cases second, because the seat is a role
	 * record and the case is what the reader opens. A case whose seat record
	 * survives its case is skipped rather than listed as a row pointing at
	 * nothing.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$coordinated = $this->seats->casesCoordinatedBy(uid: $userId);
		if ($coordinated === []) {
			return [];
		}

		$schema = trim((string)$this->settings->getConfigValue('case_schema', 'case'));
		if ($schema === '') {
			$schema = 'case';
		}

		$items = [];
		foreach ($this->rows(schema: $schema, filters: ['isFinalStatus' => false]) as $row) {
			$id = $this->idOf(row: $row);
			if ($id === '' || in_array($id, $coordinated, true) === false) {
				continue;
			}

			// The cases the reader is already HANDLING are their own group.
			// Listing them twice is two rows for one piece of work, and the
			// reader would close one and wonder about the other.
			if (trim((string)($row['assignee'] ?? '')) === $userId) {
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
