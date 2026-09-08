<?php

/**
 * Which date a resultaattype's retention term counts from.
 *
 * ZGW's `brondatumArchiefprocedure.afleidingswijze` names the source of the
 * base date, and each source is a different lookup: the case's own end date,
 * its parent case's, a named case property, or a decision's effective or
 * expiry date. That question is separate from the rule that uses the answer
 * ({@see ArchivalNominationDeriver}), and it is the half that grows: every
 * new afleidingswijze is one more branch here and none there.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Archival;

use OCA\Dossiq\Service\SettingsService;

/**
 * Resolves the zrc-021 base date for one afleidingswijze.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ArchivalBaseDateResolver {

	use ReadsConfiguredRows;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The settings bridge, for {@see ReadsConfiguredRows}.
	 *
	 * @return SettingsService The bridge to OpenRegister plus app config.
	 */
	protected function settings(): SettingsService {
		return $this->settingsService;
	}//end settings()

	/**
	 * The base date a derivation method starts from.
	 *
	 * Returns null only when the date genuinely comes from outside the case:
	 * `ander_datumkenmerk` names an external register, and an afleidingswijze
	 * this method does not know is treated the same way rather than guessed at.
	 * Every other branch falls back to the case's end date, which is what
	 * zrc-021 says and is always answerable for a closing case.
	 *
	 * @param string $method The afleidingswijze.
	 * @param string $endDate The case end date (Y-m-d).
	 * @param array<string, mixed> $case The case payload.
	 * @param array<string, mixed> $brondatum The brondatumArchiefprocedure.
	 *
	 * @return string|null The base date, or null when it cannot be resolved here.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolve(string $method, string $endDate, array $case, array $brondatum): ?string {
		return match ($method) {
			// 🔑 `afgehandeld` IS THE VALUE ZGW SENDS. The English `handled`
			// beside it is what the old ZrcController switch matched, and
			// nothing in this codebase produces it, so every resultaattype with
			// the standard Dutch value derived no date at all. Both are matched
			// now, and the Dutch one is the one that will fire.
			'afgehandeld', 'handled', 'termijn' => $endDate,
			'hoofdzaak' => ($this->parentCaseEndDate(case: $case) ?? $endDate),
			'eigenschap' => ($this->propertyDate(case: $case, brondatum: $brondatum) ?? $endDate),
			'ingangsdatum_besluit' => (
				$this->decisionDate(case: $case, fields: ['effectiveDate', 'ingangsdatum']) ?? $endDate
			),
			'vervaldatum_besluit' => (
				$this->decisionDate(case: $case, fields: ['expiryDate', 'vervaldatum']) ?? $endDate
			),
			default => null,
		};
	}//end resolve()

	/**
	 * The parent case's end date, for afleidingswijze `hoofdzaak`.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string|null The parent's end date, or null when there is none.
	 */
	private function parentCaseEndDate(array $case): ?string {
		$parentId = $this->uuidIn(
			value: (string)($case['parentCase'] ?? ($case['mainCase'] ?? ($case['hoofdzaak'] ?? '')))
		);
		if ($parentId === null) {
			return null;
		}

		return $this->asDate(
			value: (string)($this->findRow(schemaKey: 'case_schema', id: $parentId)['endDate'] ?? '')
		);
	}//end parentCaseEndDate()

	/**
	 * A named case property's date value, for afleidingswijze `eigenschap`.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param array<string, mixed> $brondatum The brondatumArchiefprocedure.
	 *
	 * @return string|null The date, or null when the property is absent or not a date.
	 */
	private function propertyDate(array $case, array $brondatum): ?string {
		$attribute = (string)($brondatum['objectAttribute'] ?? ($brondatum['datumkenmerk'] ?? ''));
		$caseId = $this->caseId(case: $case);
		if ($attribute === '' || $caseId === null) {
			return null;
		}

		$rows = $this->findRows(
			schemaKey: 'case_property_schema',
			filters: ['case' => $caseId, 'name' => $attribute, '_limit' => 1],
		);

		return $this->asDate(value: (string)(($rows[0] ?? [])['value'] ?? ''));
	}//end propertyDate()

	/**
	 * The first date a case's decisions carry in one of the named fields.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param array<int, string> $fields The decision fields to read, in order.
	 *
	 * @return string|null The date, or null when no decision carries one.
	 */
	private function decisionDate(array $case, array $fields): ?string {
		$caseId = $this->caseId(case: $case);
		if ($caseId === null) {
			return null;
		}

		$rows = $this->findRows(
			schemaKey: 'decision_schema',
			filters: ['case' => $caseId, '_limit' => 100],
		);

		foreach ($rows as $row) {
			foreach ($fields as $field) {
				$date = $this->asDate(value: (string)($row[$field] ?? ''));
				if ($date !== null) {
					return $date;
				}
			}
		}

		return null;
	}//end decisionDate()
}//end class
