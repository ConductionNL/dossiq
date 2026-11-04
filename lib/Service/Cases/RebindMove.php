<?php

/**
 * Dossiq case rebind move.
 *
 * The case being rebound, the case type and status it moves to, and why.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * One case moving onto another case type, landing in one of its statuses,
 * and what that move records on the case and answers its caller.
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
final class RebindMove {

	/**
	 * Constructor.
	 *
	 * @param string $caseId           The case uuid.
	 * @param string $targetCaseTypeId The case type it moves to.
	 * @param string $targetStatusId   The status it lands in.
	 * @param string $reason           Why, as the gate accepted it.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $targetCaseTypeId,
		public readonly string $targetStatusId,
		public readonly string $reason,
	) {
	}//end __construct()

	/**
	 * The case with this rebind appended to its own journal, with both bindings.
	 *
	 * @param array<string, mixed> $case    The case as it reads on the new case type.
	 * @param array<string, mixed> $applied The case type it leaves and both titles, who did it
	 *                                      and what happened to the answers.
	 *
	 * @return array<string, mixed> The case, with the entry appended.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function journal(array $case, array $applied): array {
		$entries = $this->priorEntries(raw: ($case['activity'] ?? null));
		$entries[] = [
			'type' => 'case-type-rebind',
			'fromCaseType' => $applied['sourceId'],
			'fromCaseTypeTitle' => $applied['fromTitle'],
			'toCaseType' => $this->targetCaseTypeId,
			'toCaseTypeTitle' => $applied['toTitle'],
			'status' => $this->targetStatusId,
			'reason' => $this->reason,
			'actor' => $applied['actorUid'],
		] + $this->impactRecord(impact: $applied['impact']) + ['timestamp' => date('c')];

		$case['activity'] = json_encode($entries);

		return $case;
	}//end journal()

	/**
	 * What this rebind applied, as the caller reads it.
	 *
	 * @param array<string, mixed> $applied The case type it left, the run, the terms and the impact.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function outcome(array $applied): array {
		$impact = $applied['impact'];

		return [
			'rebound' => true,
			'from' => $applied['sourceId'],
			'to' => $this->targetCaseTypeId,
			'status' => $this->targetStatusId,
			'reason' => $this->reason,
			'run' => ['moved' => $applied['run']['migrated'], 'reason' => $applied['run']['reason']],
			'terms' => $applied['terms'],
			'properties' => [
				'dropped' => array_column($impact['dropped'], 'name'),
				'ported' => array_map(
					static fn (array $row): array => ['from' => $row['source'], 'to' => $row['target']],
					$impact['ported']
				),
				'answered' => array_column($impact['required'], 'name'),
			],
		];
	}//end outcome()

	/**
	 * The journal entries a case already carries, as JSON text or as a list.
	 *
	 * @param mixed $raw The case's `activity`.
	 *
	 * @return array<int|string, mixed> The entries.
	 */
	private function priorEntries($raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === false || trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end priorEntries()

	/**
	 * What the rebind did to the case's answers, as the journal keeps it.
	 *
	 * THE DROPPED VALUES ARE KEPT HERE, value and all. They leave the case's
	 * `properties` in the same write, and this entry is where a reader finds
	 * what the case said before it moved.
	 *
	 * @param array<string, mixed> $impact The impact.
	 *
	 * @return array<string, mixed> The dropped, ported and answered properties.
	 */
	private function impactRecord(array $impact): array {
		return [
			'droppedProperties' => array_map(
				static fn (array $row): array => ['name' => $row['name'], 'value' => $row['value']],
				$impact['dropped']
			),
			'portedProperties' => array_map(
				static fn (array $row): array => [
					'from' => $row['source'],
					'to' => $row['target'],
					'value' => $row['value'],
					'newValue' => $row['newValue'],
				],
				$impact['ported']
			),
			'answeredProperties' => array_column($impact['required'], 'name'),
		];
	}//end impactRecord()
}//end class
