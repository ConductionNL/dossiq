<?php

/**
 * Special Category Classification
 *
 * A case type that sets `requiresSpecialCategory` processes special categories
 * of personal data (GDPR article 9 and 10) by its nature, so a case of that
 * type is not created before it says which (social-domain-consent-and-
 * safeguards, REQ-SDC-SAFE-01). The case carries them in `gdprClassification`;
 * when the case type lists its `specialDataCategories`, the classification
 * stays within that list. A case type that does not set the flag asks
 * nothing. Configuration on the case type, not a procedure (decision 182).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Refuses a case that its case type requires to be classified and that is not.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class SpecialCategoryClassification {

	/**
	 * The case field that holds the special categories.
	 */
	public const FIELD = 'gdprClassification';

	/**
	 * The refusal when the classification is missing.
	 */
	public const RULE_REQUIRED = 'special-category-required';

	/**
	 * The refusal when a category is not one the case type processes.
	 */
	public const RULE_OUTSIDE = 'special-category-outside-case-type';

	/**
	 * Refuse a case whose case type requires a special-category classification it lacks.
	 *
	 * @param array<string, mixed> $case     The case payload.
	 * @param array<string, mixed> $caseType Its effective case type.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the classification is missing or names a category the case type does not list.
	 *
	 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function assertCreatable(array $case, array $caseType): void {
		if (($caseType['requiresSpecialCategory'] ?? false) !== true) {
			return;
		}

		$categories = self::categories(value: ($case[self::FIELD] ?? null));
		if ($categories === []) {
			throw new RefusedException(
				rule: self::RULE_REQUIRED,
				sentence: 'Choose the special categories of personal data this case processes (field gdprClassification).',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$listed = self::categories(value: ($caseType['specialDataCategories'] ?? null));
		$outside = array_values(array_diff($categories, $listed));
		if ($listed !== [] && $outside !== []) {
			throw new RefusedException(
				rule: self::RULE_OUTSIDE,
				sentence: 'This case type does not process ' . implode(', ', $outside)
					. '; choose from ' . implode(', ', $listed) . ' (field gdprClassification).',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertCreatable()

	/**
	 * The non-empty category names of a list, or of one name.
	 *
	 * @param mixed $value A list of categories, one category, or nothing.
	 *
	 * @return list<string>
	 */
	private static function categories(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$categories = [];
		foreach ($value as $category) {
			if (is_string($category) === true && trim($category) !== '') {
				$categories[] = trim($category);
			}
		}

		return array_values(array_unique($categories));
	}//end categories()
}//end class
