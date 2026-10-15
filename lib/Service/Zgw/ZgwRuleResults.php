<?php

/**
 * The answers a ZGW business rule gives
 *
 * A rule passes with the body it may have enriched, or refuses with a status,
 * a detail and the fields it refuses. Every rules class and every collaborator
 * builds the same shapes here.
 *
 * A rule that could not run its check refuses too, with its own answer: the
 * request is not at fault, so it gets no field errors, and the log names the
 * rule and the object.
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

use Psr\Log\LoggerInterface;

/**
 * Builds the valid and the refused result of a rule.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwRuleResults {
	/**
	 * The status of a refusal because the check could not run.
	 *
	 * The same status the ZGW endpoints answer when OpenRegister is absent.
	 */
	public const NOT_VERIFIED_STATUS = 503;

	/**
	 * The code of a refusal because the check could not run.
	 */
	public const NOT_VERIFIED_CODE = 'check-not-completed';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse because a rule could not run its check, and log which rule and which object.
	 *
	 * A lookup that ran and found nothing is an answer. A lookup that could not
	 * run is not, so the rule refuses instead of passing. The refusal carries no
	 * field errors: nothing in the request is wrong.
	 *
	 * @param string $rule The rule, as the standard names it (drc-004).
	 * @param string $object What the rule was checking.
	 * @param string $cause Why the check could not run.
	 *
	 * @return array{valid: bool, status: int, detail: string, invalidParams: array, enrichedBody: array} With `code` set.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notVerified(string $rule, string $object, string $cause): array {
		$this->logNotVerified(rule: $rule, object: $object, cause: $cause);

		return $this->error(
			status: self::NOT_VERIFIED_STATUS,
			detail: "De controle van regel {$rule} kon niet worden uitgevoerd. Er is niets gewijzigd. Probeer het later opnieuw.",
			code: self::NOT_VERIFIED_CODE
		);
	}//end notVerified()

	/**
	 * Log that a rule could not run its check and that the request is refused for it.
	 *
	 * @param string $rule The rule.
	 * @param string $object What the rule was checking.
	 * @param string $cause Why the check could not run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function logNotVerified(string $rule, string $object, string $cause): void {
		$this->logger->error(
			'ZGW rule {rule} could not be checked for {object}, so the request is refused: {cause}',
			['rule' => $rule, 'object' => $object, 'cause' => $cause]
		);
	}//end logNotVerified()

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
	 * @param int $status HTTP status code (400, 403, or 503 when the check could not run)
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
