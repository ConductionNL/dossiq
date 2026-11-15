<?php

/**
 * Dossiq disclosure assessment validator.
 *
 * Checks one disclosure assessment before it is stored: a document, a
 * classification from the closed list, and for a classification that
 * withholds anything, at least one ground that is active and citable on the
 * settled list of refusal grounds. An unreadable list refuses the assessment.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
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
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCA\Dossiq\Woo\WooRefusalGroundsUnavailable;

/**
 * Validates a disclosure assessment against its classifications and the refusal-ground list.
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
 */
class DisclosureAssessmentValidator {

	/**
	 * Valid classification values.
	 */
	private const VALID_CLASSIFICATIONS = [
		'openbaar',
		'deels_openbaar',
		'niet_openbaar',
	];

	/**
	 * Classifications that require at least one weigeringsgrond.
	 */
	private const REQUIRES_WEIGERINGSGROND = [
		'niet_openbaar',
		'deels_openbaar',
	];

	/**
	 * Constructor.
	 *
	 * @param WooRefusalGrounds $refusalGrounds The settled list a cited ground is checked against.
	 */
	public function __construct(
		private readonly WooRefusalGrounds $refusalGrounds,
	) {
	}//end __construct()

	/**
	 * Validate a single assessment payload.
	 *
	 * @param array<string, mixed> $assessment The assessment to validate
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @throws RefusedException With 503 when the list of refusal grounds cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 */
	public function validate(array $assessment): array {
		$errors = [];

		if (empty($assessment['documentRef']) === true) {
			$errors['documentRef'] = 'documentRef is required';
		}

		return $errors + $this->classificationErrors(assessment: $assessment);
	}//end validate()

	/**
	 * Validation errors on the classification and the grounds it requires.
	 *
	 * @param array<string, mixed> $assessment The assessment to validate
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @throws RefusedException With 503 when the list of refusal grounds cannot be read.
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 */
	private function classificationErrors(array $assessment): array {
		$classification = $assessment['classification'] ?? null;
		if (empty($classification) === true) {
			return ['classification' => 'classification is required'];
		}

		if (in_array($classification, self::VALID_CLASSIFICATIONS, true) === false) {
			return ['classification' => 'Invalid classification. Must be one of: '
				. implode(', ', self::VALID_CLASSIFICATIONS)];
		}

		if (in_array($classification, self::REQUIRES_WEIGERINGSGROND, true) === true) {
			return $this->groundsErrors(assessment: $assessment, classification: (string)$classification);
		}

		return [];
	}//end classificationErrors()

	/**
	 * Validation errors on the weigeringsgronden a classification requires.
	 *
	 * @param array<string, mixed> $assessment     The assessment to validate
	 * @param string               $classification The classification, one that requires grounds
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @throws RefusedException With 503 when the list of refusal grounds cannot be read.
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 */
	private function groundsErrors(array $assessment, string $classification): array {
		$grounds = $assessment['weigeringsgronden'] ?? [];
		if (empty($grounds) === true) {
			return ['weigeringsgronden' => 'At least one weigeringsgrond is required for '
				. $classification . ' (WOO Art. 5.1/5.2)'];
		}

		$refusal = $this->refusalOfGrounds(grounds: (array)$grounds);
		if ($refusal !== null) {
			return ['weigeringsgronden' => $refusal];
		}

		return [];
	}//end groundsErrors()

	/**
	 * Why the cited grounds cannot stand, or null when every one can.
	 *
	 * Each code must be an active, citable entry of the settled list
	 * (woo-refusal-grounds-list, decision 133). An unreadable list refuses
	 * the assessment rather than accepting any value or falling back to a
	 * constant.
	 *
	 * @param array<int, mixed> $grounds The cited codes.
	 *
	 * @return string|null The refusal sentence, or null.
	 *
	 * @throws RefusedException With 503 when the list cannot be read.
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 */
	private function refusalOfGrounds(array $grounds): ?string {
		foreach ($grounds as $code) {
			$code = trim((string)$code);
			$refusal = $this->refusalOfGround(code: $code, ground: $this->groundOf(code: $code));
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}//end refusalOfGrounds()

	/**
	 * The settled list's entry for a code.
	 *
	 * @param string $code The cited code.
	 *
	 * @return array<string, mixed>|null The entry, or null when the code is unknown.
	 *
	 * @throws RefusedException With 503 when the list cannot be read.
	 */
	private function groundOf(string $code): ?array {
		try {
			$ground = $this->refusalGrounds->byCode(code: $code);
		} catch (WooRefusalGroundsUnavailable $e) {
			throw new RefusedException(
				rule: 'woo-refusal-grounds-unavailable',
				sentence: 'The list of Woo refusal grounds cannot be read, so the assessment is not stored. Try again later.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $ground;
	}//end groundOf()

	/**
	 * Why one cited ground cannot stand, or null when it can.
	 *
	 * @param string                    $code   The cited code.
	 * @param array<string, mixed>|null $ground The settled list's entry for it.
	 *
	 * @return string|null The refusal sentence, or null.
	 */
	private function refusalOfGround(string $code, array|null $ground): string|null {
		if ($ground === null) {
			return 'Unknown weigeringsgrond: ' . $code;
		}

		if ($ground['citable'] !== true) {
			return 'Weigeringsgrond ' . $code . ' groups other grounds and cannot be cited. Pick one of the grounds under it.';
		}

		if ($ground['status'] !== 'active') {
			return 'Weigeringsgrond ' . $code . ' is retired and cannot be cited on a new assessment.';
		}

		return null;
	}//end refusalOfGround()
}//end class
