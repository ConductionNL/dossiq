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
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\SettingsService;
use RuntimeException;
use Throwable;

/**
 * Reads what the case-flow seed has already created.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
class CaseFlowSeedIndex {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the ObjectService and reads the seed markers.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Whether this run plants the demo cases.
	 *
	 * ONCE PER SEED VERSION, AND ONLY ON AN INSTANCE THAT OPTED IN. The cases
	 * were looked up by title on every repair and upgrade and created again
	 * when missing, so a demo case an operator deleted came back (and one
	 * whose title lookup missed came back twice). The case type and its
	 * statuses are configuration and are still completed on every run.
	 *
	 * @param array<string,mixed> $data The decoded seed.
	 *
	 * @return bool True when the demo cases are due.
	 *
	 * @spec openspec/changes/seed-cases-once/specs/seed-cases-once/spec.md#requirement-the-demo-cases-are-seeded-once
	 */
	public function casesDue(array $data): bool {
		if ($this->settingsService->getConfigValue(key: DemoDataService::DECIDED_KEY) !== DemoDataService::DECIDED_INSTALLED) {
			return false;
		}

		return $this->settingsService->getConfigValue(key: CaseFlowSeedDataRepairStep::CASES_SEEDED_KEY) !== $this->seedVersion(data: $data);
	}//end casesDue()

	/**
	 * The seed's version, as the marker records it.
	 *
	 * @param array<string,mixed> $data The decoded seed.
	 *
	 * @return string The version, `0` when the seed names none.
	 */
	public function seedVersion(array $data): string {
		$version = trim((string)($data['version'] ?? ''));
		if ($version === '') {
			return '0';
		}

		return $version;
	}//end seedVersion()

	/**
	 * An existing case type with this slug, or null.
	 *
	 * The seed names each case type by a slug, and the slug is what makes a
	 * rerun find what an earlier run created. Looking it up by title found
	 * nothing: `title` is translatable, so OpenRegister stores it as a
	 * language map and a filter on the plain string matches no row. Every
	 * repair pass then created the case type again, which is how
	 * "Omgevingsvergunning kleine bouwactiviteit" came to be offered twice
	 * (review-check, 11 October 2026, N-5).
	 *
	 * @param array<string,string> $schemas Register and schema names.
	 * @param string               $slug    The slug the seed gives the case type.
	 *
	 * @return array<string,mixed>|null The case type, or null.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function caseTypeBySlug(array $schemas, string $slug): ?array {
		$rows = $this->rows(
			query: [
				'@self' => ['register' => $schemas['register'], 'schema' => $schemas['caseType'], 'slug' => $slug],
				'_limit' => 5,
			]
		);

		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end caseTypeBySlug()

	/**
	 * An existing case type with this title, or null.
	 *
	 * @param array<string,string> $schemas Register and schema names.
	 * @param string               $title   The title to look for.
	 *
	 * @return array<string,mixed>|null The case type, or null.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function rows(array $query): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			// Unscoped: a repair run has no user to scope the read to.
			$found = $objectService->searchObjects($query, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// An unreadable store is not an empty one; the caller decides.
			throw new RuntimeException('the store could not be read: ' . $e->getMessage(), 0, $e);
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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
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
