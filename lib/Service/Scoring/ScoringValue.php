<?php

/**
 * ScoringValue
 *
 * Reads the values a scoring formula works with: numbers, case answers, ids and prices.
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Scoring
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Scoring;


/**
 * Reads the values a scoring formula works with: numbers, case answers, ids and prices.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Scoring
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
final class ScoringValue {
	/**
	 * The lowest positive price among the submissions.
	 *
	 * @param list<array<string, mixed>> $submissions The submissions.
	 *
	 * @return float|null The lowest price, or null when none names one.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function lowestPriceOf(array $submissions): ?float {
		$lowest = null;
		foreach ($submissions as $submission) {
			$price = $this->numberOf(value: ($submission['priceAmount'] ?? null));
			if ($price !== null && $price > 0.0 && ($lowest === null || $price < $lowest)) {
				$lowest = $price;
			}
		}

		return $lowest;
	}//end lowestPriceOf()

	/**
	 * A case value, on the case itself or among its property answers.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $field The field name.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function caseValue(array $case, string $field): mixed {
		if (isset($case[$field]) === true) {
			return $case[$field];
		}

		foreach ((array)($case['properties'] ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['name'] ?? '') === $field) {
				return ($entry['value'] ?? null);
			}
		}

		return null;
	}//end caseValue()

	/**
	 * How a submission is named in the sheet.
	 *
	 * @param array<string, mixed> $submission The submission.
	 *
	 * @return string The reference, else the supplier, else the id.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function labelOf(array $submission): string {
		foreach (['submissionRef', 'supplier'] as $field) {
			$value = trim((string)($submission[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return $this->idOf(row: $submission);
	}//end labelOf()

	/**
	 * A number, from a number or a numeric string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null The number, or null.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function numberOf(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric(trim($value)) === true) {
			return (float)trim($value);
		}

		return null;
	}//end numberOf()

	/**
	 * The id of a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()
}//end class
