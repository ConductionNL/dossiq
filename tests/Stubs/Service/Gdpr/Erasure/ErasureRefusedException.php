<?php

/**
 * Stub of OpenRegister's erasure refusal.
 *
 * Dossiq resolves OpenRegister's GDPR services by name, so nothing in `lib/`
 * type-hints this class. It exists here so a test can THROW the real shape and
 * watch {@see \OCA\Dossiq\Service\Gdpr\PlatformDataSubjectRights} carry the
 * rule and the status across. A test that threw a plain RuntimeException
 * instead would exercise the fallback branch and report it as the pass-through
 * branch, which is the failure mode the pass-through exists to prevent.
 *
 * Shape taken from openregister `lib/Service/Gdpr/Erasure/ErasureRefusedException.php`
 * at 6c820b3d2 (#3759).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use Exception;
use Throwable;

/**
 * The platform's refusal, carrying a rule and the status to answer with.
 */
class ErasureRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string               $rule       Machine-readable rule id.
	 * @param string               $reason     The sentence a person reads.
	 * @param int                  $statusCode The HTTP status.
	 * @param array<string, mixed> $context    Anything else the caller needs.
	 * @param Throwable|null       $previous   Previous exception.
	 */
	public function __construct(
		private readonly string $rule,
		string $reason,
		private readonly int $statusCode = 409,
		private readonly array $context = [],
		?Throwable $previous = null,
	) {
		parent::__construct($reason, 0, $previous);
	}//end __construct()

	/**
	 * The rule id.
	 *
	 * @return string
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()

	/**
	 * The status code.
	 *
	 * @return int
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The response body.
	 *
	 * @return array<string, mixed>
	 */
	public function toResponseBody(): array {
		return array_merge(
			['error' => 'ERASURE_REFUSED', 'rule' => $this->rule, 'message' => $this->getMessage()],
			$this->context,
		);
	}//end toResponseBody()
}//end class
