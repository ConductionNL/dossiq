<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's decision-evaluation exception.
 *
 * dossiq catches this by type in DecisionTableController and
 * EvaluateDecisionHandler and maps its error code onto an HTTP status, so a
 * unit test cannot load those classes without it. Mirrors openregister
 * lib/Service/Dmn/DecisionEvaluationException.php — if the real error codes or
 * accessors change, this stub is where dossiq finds out.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Dmn
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

use RuntimeException;

/**
 * Stub of OpenRegister's DecisionEvaluationException.
 */
class DecisionEvaluationException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string               $errorCode Stable machine-readable error code.
	 * @param array<string, mixed> $details   Optional structured details.
	 */
	public function __construct(
		private readonly string $errorCode,
		private readonly array $details = [],
	) {
		parent::__construct(message: $errorCode);

	}//end __construct()

	/**
	 * The stable error code.
	 *
	 * @return string The code.
	 */
	public function getErrorCode(): string {
		return $this->errorCode;

	}//end getErrorCode()

	/**
	 * Structured details for logging.
	 *
	 * @return array<string, mixed> The details.
	 */
	public function getDetails(): array {
		return $this->details;

	}//end getDetails()

}//end class
