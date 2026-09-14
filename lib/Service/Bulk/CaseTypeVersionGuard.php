<?php

/**
 * Dossiq: refuse a bulk attribute change that spans two case type versions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bulk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bulk;

use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * The guard that makes a dry run worth having.
 *
 * A case type is never edited once cases run on it: a new version is a new
 * object carrying its own statuses, results and properties. So a bulk write
 * into one field across two versions writes a value into a field that means
 * two things, and the damage is not that it is hard to undo, it is that
 * afterwards nobody can read which meaning was intended.
 *
 * The refusal is raised at SELECTION time, before the rehearsal, because a dry
 * run of an act that should never have been offered is a dry run of the wrong
 * question (D-4).
 *
 * ⚠️ This is not a duplicate of OpenRegister's `homogeneity` guard, which reads
 * the SCHEMA version an object was written against. This reads the CASE TYPE
 * version the case runs on, which is where the vocabulary lives. Both are
 * declared: the engine's guard also covers a selection given as a query, which
 * this one does not resolve.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class CaseTypeVersionGuard {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration and the object service.
	 * @param CaseTypeStore   $caseTypes       Case type reads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
	) {
	}//end __construct()

	/**
	 * Refuse a selection whose cases run on two versions of one case type.
	 *
	 * @param array<int, string> $caseIds The selected case uuids.
	 *
	 * @return void
	 *
	 * @throws MixedCaseTypeVersionsException When the selection spans versions.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function assertOneVersion(array $caseIds): void {
		$caseTypeIds = $this->caseTypeIdsOf(caseIds: $caseIds);
		if (count($caseTypeIds) < 2) {
			return;
		}

		$families = [];
		foreach ($caseTypeIds as $caseTypeId => $caseCount) {
			$caseType = $this->caseTypes->readCaseType(caseTypeId: (string)$caseTypeId);
			if ($caseType === []) {
				continue;
			}

			$family = $this->familyOf(caseType: $caseType, caseTypeId: (string)$caseTypeId);
			$version = (int)($caseType['version'] ?? 1);

			$families[$family]['title'] = (string)($caseType['title'] ?? $family);
			$families[$family]['counts'][(string)$version] = (($families[$family]['counts'][(string)$version] ?? 0) + $caseCount);
		}

		foreach ($families as $family) {
			$counts = ($family['counts'] ?? []);
			if (count($counts) < 2) {
				continue;
			}

			$versions = array_map('intval', array_keys($counts));
			sort($versions);

			throw new MixedCaseTypeVersionsException(
				caseTypeTitle: (string)($family['title'] ?? ''),
				versions: $versions,
				counts: $counts,
			);
		}
	}//end assertOneVersion()

	/**
	 * The case type of every selected case, and how many cases sit on it.
	 *
	 * A case that cannot be read is left out rather than counted as a case type
	 * of its own: it is already going to be reported as a failed member, and
	 * inventing a version for it would refuse the whole act over one bad row.
	 *
	 * @param array<int, string> $caseIds The selected case uuids.
	 *
	 * @return array<string, int> Case type uuid to case count.
	 */
	private function caseTypeIdsOf(array $caseIds): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$found = [];
		foreach ($caseIds as $caseId) {
			$caseId = trim((string)$caseId);
			if ($caseId === '') {
				continue;
			}

			try {
				$case = $objectService->find($caseId, register: $register, schema: $schema);
			} catch (Throwable $e) {
				continue;
			}

			$row = $this->asArray(value: $case);
			$caseTypeId = $this->caseTypes->referenceId(value: ($row['caseType'] ?? ''));
			if ($caseTypeId === '') {
				continue;
			}

			$found[$caseTypeId] = (($found[$caseTypeId] ?? 0) + 1);
		}

		return $found;
	}//end caseTypeIdsOf()

	/**
	 * The uuid of the oldest version in this case type's chain, which is what
	 * makes two versions of one case type one family.
	 *
	 * Walks `previousVersion` back, with a hop cap: a chain that points at
	 * itself would otherwise loop here rather than being refused as data.
	 *
	 * @param array<string, mixed> $caseType   The case type row.
	 * @param string               $caseTypeId The row's own uuid.
	 *
	 * @return string The family key.
	 */
	private function familyOf(array $caseType, string $caseTypeId): string {
		$current = $caseType;
		$currentId = $caseTypeId;
		$seen = [$caseTypeId => true];

		for ($hop = 0; $hop < 32; $hop++) {
			$previousId = $this->caseTypes->referenceId(value: ($current['previousVersion'] ?? ''));
			if ($previousId === '' || isset($seen[$previousId]) === true) {
				return $currentId;
			}

			$previous = $this->caseTypes->readCaseType(caseTypeId: $previousId);
			if ($previous === []) {
				return $previousId;
			}

			$seen[$previousId] = true;
			$current = $previous;
			$currentId = $previousId;
		}

		return $currentId;
	}//end familyOf()

	/**
	 * Read an object service answer as an array.
	 *
	 * @param mixed $value Whatever the object service returned.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$object = $value->getObject();

			return (is_array($object) === true) ? $object : [];
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();

			return (is_array($serialised) === true) ? $serialised : [];
		}

		return [];
	}//end asArray()
}//end class
