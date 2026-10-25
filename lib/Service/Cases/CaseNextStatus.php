<?php

/**
 * The status a case moves to next, read from its case type's statuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IL10N;
use Throwable;

/**
 * Board DqTaak "Wat deze taak tegenhoudt": "De zaak gaat pas naar Beslissing
 * op bezwaar als deze taak klaar is." The next status is the one after the
 * case's current status in its case type's order, the same rule as the case
 * page's next step (src/utils/caseNextStep.js). The case and the statuses are
 * read under the user's rights: a case the user may not read gives no answer.
 *
 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
 */
class CaseNextStatus {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and the object service.
	 * @param IL10N           $l10n            The user's language, for a language-tagged name.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The case's next status, `{name, number}`, `[]` when it has none, or null
	 * when the user may not read the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{name?: string, number?: int}|null
	 *
	 * @throws Throwable When the register cannot be read.
	 *
	 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
	 */
	public function forCase(string $caseId): ?array {
		$case = ($this->rows(schema: 'case', filters: ['_ids' => [$caseId], '_limit' => 1])[0] ?? null);
		if (is_array($case) === false) {
			return null;
		}

		$caseType = $this->idOf(value: $case['caseType'] ?? null);
		$current = $this->idOf(value: $case['status'] ?? null);
		if ($caseType === '' || $current === '') {
			return [];
		}

		$ordered = $this->ordered(statuses: $this->rows(schema: 'statusType', filters: ['caseType' => $caseType, '_limit' => 100]));
		foreach ($ordered as $index => $status) {
			if ($this->idOf(value: $status['id'] ?? null) === $current && isset($ordered[$index + 1]) === true) {
				return ['name' => $this->nameOf(name: $ordered[$index + 1]['name'] ?? ''), 'number' => $index + 2];
			}
		}

		return [];
	}//end forCase()

	/**
	 * The statuses in their case type's order (`order`, then as stored).
	 *
	 * @param array<int, array<string, mixed>> $statuses The statuses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ordered(array $statuses): array {
		$keyed = [];
		foreach (array_values($statuses) as $index => $status) {
			$keyed[] = ['order' => (float)($status['order'] ?? $index), 'index' => $index, 'status' => $status];
		}

		usort($keyed, static fn (array $one, array $two): int => [$one['order'], $one['index']] <=> [$two['order'], $two['index']]);

		return array_map(static fn (array $entry): array => $entry['status'], $keyed);
	}//end ordered()

	/**
	 * A status name in the user's language: a plain string, or `{nl, en}`.
	 *
	 * @param mixed $name The stored name.
	 *
	 * @return string
	 */
	private function nameOf(mixed $name): string {
		if (is_array($name) === false) {
			return trim((string)$name);
		}

		$language = substr($this->l10n->getLanguageCode(), 0, 2);
		foreach ([$language, 'nl', 'en'] as $key) {
			if (is_string($name[$key] ?? null) === true && trim($name[$key]) !== '') {
				return trim($name[$key]);
			}
		}

		return '';
	}//end nameOf()

	/**
	 * The id of a reference: a uuid string, or an extended object's id.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string
	 */
	private function idOf(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end idOf()

	/**
	 * Rows of one schema under the user's rights.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws Throwable When the register cannot be read; the caller answers that, not "not found".
	 */
	private function rows(string $schema, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
	}//end rows()
}//end class
