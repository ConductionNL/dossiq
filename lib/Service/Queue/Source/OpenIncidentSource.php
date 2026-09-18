<?php

/**
 * Open incidents handed to the reader.
 *
 * The reason this is a source of its own rather than a filter on
 * {@see AssignedCasesSource}: an incident's owner is NOT the case's owner. An
 * inspector holding one report inside a case the area handler holds would
 * appear on nobody's queue if open incidents were read off the case's assignee,
 * and the case would appear on the area handler's queue with no sign that
 * somebody else is working part of it.
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
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

use OCA\Dossiq\Service\Cases\IncidentStore;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCP\IL10N;

/**
 * The reports one person is working, wherever their cases sit.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class OpenIncidentSource implements QueueSource {

	/**
	 * Constructor.
	 *
	 * @param IncidentStore $incidents Reads the incidents a person owns.
	 * @param IL10N           $l10n      Translations.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly IncidentStore $incidents,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The source's machine name.
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return 'openIncidents';
	}//end name()

	/**
	 * What a person reads this source as.
	 *
	 * @return string The label.
	 */
	public function label(): string {
		return $this->l10n->t('Reports assigned to you');
	}//end label()

	/**
	 * What takes an item off this queue.
	 *
	 * @return string The sentence.
	 */
	public function closesWhen(): string {
		return $this->l10n->t('The report is settled, or handed to somebody else.');
	}//end closesWhen()

	/**
	 * The mechanisms that put something here.
	 *
	 * @return array<int, string> The mechanisms.
	 */
	public function mechanisms(): array {
		return ['incident-assignment'];
	}//end mechanisms()

	/**
	 * The open incidents this person owns.
	 *
	 * @param string $userId The reader.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-48
	 */
	public function itemsFor(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$items = [];
		foreach ($this->incidents->ownedBy(userId: $userId) as $incident) {
			$caseId = trim((string)($incident['case'] ?? ''));
			$title = trim((string)($incident['description'] ?? ''));
			if ($title === '') {
				$title = $this->l10n->t('A report with no description');
			}

			$route = ['name' => 'PersonalQueue'];
			if ($caseId !== '') {
				$route = ['name' => 'CaseDetail', 'params' => ['id' => $caseId]];
			}

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'incident',
				subjectId: trim((string)($incident['id'] ?? ($incident['uuid'] ?? ''))),
				title: $title,
				priority: '',
				// THE EVENT DATE, NOT THE RECORDING MOMENT. A report from three
				// weeks ago is older work than one filed this morning, and a
				// queue ordered on when somebody typed it up says the opposite.
				dueAt: $this->dayOf(value: (string)($incident['eventDate'] ?? '')),
				coveredFor: null,
				// The route goes to the CASE. There is no incident page, and
				// what a person needs in order to work a report is the case it
				// sits in: the address, the history and the other reports on it.
				route: $route,
				waiting: [],
				subject: $incident
			);
		}

		return $items;
	}//end itemsFor()

	/**
	 * One stored moment as a plain day, or null.
	 *
	 * @param string $value The stored moment.
	 *
	 * @return string|null The day.
	 */
	private function dayOf(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		return substr($value, 0, 10);
	}//end dayOf()
}//end class
