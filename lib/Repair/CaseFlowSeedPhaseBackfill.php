<?php

/**
 * Which stored statuses of a seeded case type still lack the seed's phase.
 *
 * A status is only created once, so one seeded before statuses carried a
 * phase kept none, and the process analysis drew it as a column of its own.
 * This answers which stored rows to fill; {@see CaseFlowSeedDataRepairStep}
 * writes them. A phase an administrator set is left alone: only an empty one
 * is filled.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/seed-status-phases-backfill/spec.md#requirement-a-seeded-status-without-a-phase-gets-the-seeds-phase
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Support\LanguageMapText;

/**
 * Matches a seed's status phases against the stored statuses.
 */
class CaseFlowSeedPhaseBackfill {

	/**
	 * Constructor.
	 *
	 * @param LanguageMapText $text Reads a translatable name as text.
	 */
	public function __construct(
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * The phase to write per stored status id.
	 *
	 * @param array<int,mixed>                $statusTypes The statuses declared in the seed.
	 * @param iterable<array<string,mixed>>   $rows        The stored statuses of the case type.
	 * @param string                          $caseTypeId  The owning case type.
	 *
	 * @return array<string,mixed> Phase per status id, for the rows without one.
	 *
	 * @spec openspec/specs/seed-status-phases-backfill/spec.md#requirement-a-seeded-status-without-a-phase-gets-the-seeds-phase
	 */
	public function pending(array $statusTypes, iterable $rows, string $caseTypeId): array {
		$phases = $this->phasesByName(statusTypes: $statusTypes);
		$pending = [];
		foreach ($rows as $row) {
			$name = $this->text->textOf(value: ($row['name'] ?? null));
			$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			$own = ((string)($row['caseType'] ?? '') === $caseTypeId);
			if ($id === '' || $own === false || isset($phases[$name]) === false || empty($row['phase']) === false) {
				continue;
			}

			$pending[$id] = $phases[$name];
		}

		return $pending;
	}//end pending()

	/**
	 * The seed's phase per status name, for the statuses that name one.
	 *
	 * @param array<int,mixed> $statusTypes The statuses declared in the seed.
	 *
	 * @return array<string,mixed> Phase per status name.
	 */
	private function phasesByName(array $statusTypes): array {
		$phases = [];
		foreach ($statusTypes as $status) {
			if (is_array($status) === false) {
				continue;
			}

			$name = $this->text->textOf(value: ($status['name'] ?? null));
			$phase = ($status['phase'] ?? null);
			if ($name !== '' && $phase !== null && $phase !== '') {
				$phases[$name] = $phase;
			}
		}

		return $phases;
	}//end phasesByName()
}//end class
