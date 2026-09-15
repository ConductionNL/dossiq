<?php

/**
 * The consultations somebody asked this person for.
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
use OCP\IL10N;

/**
 * Open consultations assigned to the reader.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class ConsultationSource extends RegisterBackedSource {
	/**
	 * The statuses a consultation is still waiting in.
	 *
	 * Written as the set that is OPEN rather than the set that is closed: a
	 * status somebody adds later is then waiting work by default, which is the
	 * safe direction for a queue to be wrong in.
	 *
	 * @var array<int, string>
	 */
	private const CLOSED_STATUSES = ['beantwoord', 'ingetrokken', 'vervallen', 'afgerond'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration.
	 * @param IL10N           $l10n     Translations.
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
		return 'consultations';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Advice asked of you');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A consultation leaves when you answer it, or when it is withdrawn.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return ['notification:onCreate', 'flow:DossiqEnsureCommitteeNode'];
	}//end mechanisms()

	/**
	 * The consultations still waiting on this person.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$rows = $this->rows(schema: 'consultation', filters: ['assignee' => $userId]);

		$items = [];
		foreach ($rows as $row) {
			$id = $this->idOf(row: $row);
			$status = strtolower(trim((string)($row['status'] ?? '')));
			if ($id === '' || in_array($status, self::CLOSED_STATUSES, true) === true) {
				continue;
			}

			$case = trim((string)($row['parentCase'] ?? ''));

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'consultation',
				subjectId: $id,
				title: (string)($row['subject'] ?? $id),
				priority: (string)($row['priority'] ?? ''),
				dueAt: $this->dateOf(row: $row, key: 'latestResponseDate'),
				coveredFor: null,
				route: ($case === ''
					? ['name' => 'Cases']
					: ['name' => 'CaseDetail', 'params' => ['id' => $case], 'query' => ['tab' => 'consultations']])
			);
		}

		return $items;
	}//end itemsFor()
}//end class
