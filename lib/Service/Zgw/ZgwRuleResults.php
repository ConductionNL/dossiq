<?php

/**
 * The answers a ZGW business rule gives
 *
 * A rule passes with the body it may have enriched, or refuses with a status,
 * a detail and the fields it refuses. Stateless: every rules class and every
 * collaborator builds the same shapes here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Builds the valid and the refused result of a rule.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwRuleResults {
	/**
	 * Build a successful validation result (pass-through).
	 *
	 * @param array $body The (possibly enriched) request body
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function isValid(array $body): array {
		return [
			'valid' => true,
			'status' => 200,
			'detail' => '',
			'enrichedBody' => $body,
		];
	}//end isValid()

	/**
	 * Build a validation error result.
	 *
	 * @param int $status HTTP status code (400 or 403)
	 * @param string $detail Error detail message
	 * @param array $invalidParams Invalid parameter entries
	 * @param string $code Optional error code
	 *
	 * @return array{valid: bool, status: int, detail: string, invalidParams: array, enrichedBody: array}
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function error(
		int $status,
		string $detail,
		array $invalidParams = [],
		string $code = '',
	): array {
		$result = [
			'valid' => false,
			'status' => $status,
			'detail' => $detail,
			'invalidParams' => $invalidParams,
			'enrichedBody' => [],
		];
		if ($code !== '') {
			$result['code'] = $code;
		}

		return $result;
	}//end error()

	/**
	 * Build a field-level validation error.
	 *
	 * @param string $fieldName The field name
	 * @param string $code The error code
	 * @param string $reason The error reason
	 *
	 * @return array{name: string, code: string, reason: string}
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function fieldError(string $fieldName, string $code, string $reason): array {
		return [
			'name' => $fieldName,
			'code' => $code,
			'reason' => $reason,
		];
	}//end fieldError()

	/**
	 * Build a field immutability error response.
	 *
	 * @param string $fieldName The immutable field name
	 *
	 * @return array The validation error result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function fieldImmutableError(string $fieldName): array {
		$detail = "Het veld {$fieldName} mag niet gewijzigd worden.";
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(
					fieldName: $fieldName,
					code: 'wijzigen-niet-toegelaten',
					reason: $detail
				),
			]
		);
	}//end fieldImmutableError()
}//end class
