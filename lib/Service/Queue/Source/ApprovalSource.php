<?php

/**
 * The advice requests waiting on this person's signature.
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
 * Open advice requests addressed to the reader.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class ApprovalSource extends RegisterBackedSource {
	/**
	 * The statuses an advice request has finished in: the register's own
	 * (`received`, `expired`) and the Dutch ones older rows still carry.
	 *
	 * @var array<int, string>
	 */
	private const CLOSED_STATUSES = ['received', 'expired', 'ontvangen', 'verwerkt', 'vervallen', 'ingetrokken', 'afgerond'];

	/**
	 * The case tab that holds the advice card, by the id the tabs widget
	 * opens from the route hash.
	 *
	 * @var string
	 */
	public const ADVICE_TAB = 'case-decisions-panel';

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
		return 'approvals';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('Waiting for your signature');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A request leaves when you sign it, or when it is withdrawn.');
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
	 * The advice requests still waiting on this person.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 * @spec openspec/changes/advice-and-consultation-on-the-case/specs/advice-management/spec.md#requirement-a-handler-asks-for-advice-from-the-case-req-adv-case-01
	 */
	public function itemsFor(string $userId): array {
		$rows = $this->rows(schema: 'adviesAanvraag', filters: ['advisor' => $userId]);

		$items = [];
		foreach ($rows as $row) {
			$id = $this->idOf(row: $row);
			$status = strtolower(trim((string)($row['status'] ?? '')));
			if ($id === '' || in_array($status, self::CLOSED_STATUSES, true) === true) {
				continue;
			}

			$case = trim((string)($row['case'] ?? ''));
			$route = ['name' => 'Cases'];
			if ($case !== '') {
				$route = ['name' => 'CaseDetail', 'params' => ['id' => $case], 'hash' => '#' . self::ADVICE_TAB];
			}

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'advice',
				subjectId: $id,
				title: (string)($row['subject'] ?? $id),
				priority: '',
				dueAt: $this->dateOf(row: $row, key: 'deadline'),
				coveredFor: null,
				route: $route
			);
		}

		return $items;
	}//end itemsFor()
}//end class
