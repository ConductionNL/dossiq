<?php

/**
 * What the case-flow seed has already written.
 *
 * Split out of {@see CaseFlowSeedDataRepairStep} so the step is about WRITING
 * and this is about READING. It also keeps that class under its complexity
 * ceiling, but the division is the honest one: every method here answers
 * "does this already exist", which is what makes the seed idempotent per
 * object rather than all-or-nothing.
 *
 * Statuses are deliberately NOT read here — {@see StatusTypeLookup} already
 * answers that, and the seed consults it. Two readers of one relationship
 * drift, and the one that drifts is the one nobody is looking at.
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
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * Reads what the case-flow seed has already created.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
class CaseFlowSeedIndex {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the ObjectService.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * An existing case type with this title, or null.
	 *
	 * @param array<string,string> $schemas Register and schema names.
	 * @param string               $title   The title to look for.
	 *
	 * @return array<string,mixed>|null The case type, or null.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function caseTypeByTitle(array $schemas, string $title): ?array {
		$rows = $this->rows(
			query: [
				'@self' => ['register' => $schemas['register'], 'schema' => $schemas['caseType']],
				'title' => $title,
				'_limit' => 5,
			]
		);

		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end caseTypeByTitle()

	/**
	 * The titles of cases already seeded for this case type.
	 *
	 * @param array<string,string> $schemas    Register and schema names.
	 * @param string               $caseTypeId The case type.
	 *
	 * @return string[] The titles.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function caseTitlesFor(array $schemas, string $caseTypeId): array {
		$titles = [];
		foreach ($this->rows(
			query: [
				'@self' => ['register' => $schemas['register'], 'schema' => $schemas['case']],
				'caseType' => $caseTypeId,
				'_limit' => 200,
			]
		) as $row) {
			$title = (string)($row['title'] ?? '');
			if ($title !== '') {
				$titles[] = $title;
			}
		}

		return $titles;
	}//end caseTitlesFor()

	/**
	 * Run a search and normalise whatever shape it returns to plain rows.
	 *
	 * The store answers with either a bare list or a paged envelope, and each
	 * row as an array or an entity. Reading only one of those shapes is how a
	 * reader silently finds nothing on an instance that answers the other way.
	 *
	 * @param array<string,mixed> $query The search query.
	 *
	 * @return array<int, array<string,mixed>> The rows.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function rows(array $query): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$found = $objectService->searchObjects($query);
		} catch (Throwable $e) {
			return [];
		}

		return $this->normalise(value: $found);
	}//end rows()

	/**
	 * Flatten a search result to plain arrays.
	 *
	 * @param mixed $value The search result.
	 *
	 * @return array<int, array<string,mixed>> The rows.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function normalise(mixed $value): array {
		if (is_array($value) === true && isset($value['results']) === true) {
			$value = $value['results'];
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end normalise()
}//end class
