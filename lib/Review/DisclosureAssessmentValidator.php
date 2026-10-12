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
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 */
	public function validate(array $assessment): array {
		$errors = [];

		if (empty($assessment['documentRef']) === true) {
			$errors['documentRef'] = 'documentRef is required';
		}

		$classification = $assessment['classification'] ?? null;
		if (empty($classification) === true) {
			$errors['classification'] = 'classification is required';
		} elseif (in_array($classification, self::VALID_CLASSIFICATIONS, true) === false) {
			$errors['classification'] = 'Invalid classification. Must be one of: '
				. implode(', ', self::VALID_CLASSIFICATIONS);
		} elseif (in_array($classification, self::REQUIRES_WEIGERINGSGROND, true) === true) {
			$grounds = $assessment['weigeringsgronden'] ?? [];
			if (empty($grounds) === true) {
				$errors['weigeringsgronden'] = 'At least one weigeringsgrond is required for '
					. $classification . ' (WOO Art. 5.1/5.2)';
				return $errors;
			}

			$refusal = $this->refusalOfGrounds(grounds: (array)$grounds);
			if ($refusal !== null) {
				$errors['weigeringsgronden'] = $refusal;
			}
		}

		return $errors;
	}//end validate()

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
			try {
				$ground = $this->refusalGrounds->byCode(code: $code);
			} catch (WooRefusalGroundsUnavailable $e) {
				throw new RefusedException(
					rule: 'woo-refusal-grounds-unavailable',
					sentence: 'The list of Woo refusal grounds cannot be read, so the assessment is not stored. Try again later.',
					status: RefusedException::STATUS_INDETERMINATE,
				);
			}

			if ($ground === null) {
				return 'Unknown weigeringsgrond: ' . $code;
			}

			if ($ground['citable'] !== true) {
				return 'Weigeringsgrond ' . $code . ' groups other grounds and cannot be cited. Pick one of the grounds under it.';
			}

			if ($ground['status'] !== 'active') {
				return 'Weigeringsgrond ' . $code . ' is retired and cannot be cited on a new assessment.';
			}
		}//end foreach

		return null;
	}//end refusalOfGrounds()
}//end class
