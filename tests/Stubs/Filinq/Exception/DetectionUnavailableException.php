<?php

/**
 * Filinq DetectionUnavailableException test stub.
 *
 * A verbatim copy of filinq's class (lib/Exception/DetectionUnavailableException.php
 * on filinq fix/anonymisation-fails-closed 44d5fc0c, read 2026-09-29), so the
 * dossiq code that recognises it can be tested without filinq installed.
 * tests/bootstrap.php loads it only when the real class is absent.
 * If filinq changes the class, change this copy with it. Its spec links
 * point into filinq's repository and are left out here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Filinq\Exception;

use RuntimeException;

/**
 * The detector gate refused: a copy of the input filed as an anonymised
 * document is the failure it exists to prevent.
 */
class DetectionUnavailableException extends RuntimeException {

	/**
	 * Filinq could not read which detector is live.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'detection_state_unknown';

	/**
	 * Entity recognition is switched off on this instance.
	 *
	 * @var string
	 */
	public const REASON_DISABLED = 'detection_disabled';

	/**
	 * The backend OpenRegister would use says it is unavailable.
	 *
	 * @var string
	 */
	public const REASON_UNAVAILABLE = 'detection_backend_unavailable';

	/**
	 * Constructor.
	 *
	 * @param string $reason  One of the REASON_* constants.
	 * @param string $message What went wrong, in English, for the log.
	 * @param string $backend The effective backend OpenRegister named, '' when unknown.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $reason,
		string $message,
		private readonly string $backend = '',
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * Why the run was refused.
	 *
	 * @return string The reason.
	 */
	public function getReason(): string {
		return $this->reason;

	}//end getReason()

	/**
	 * The backend OpenRegister named, '' when the state was unknown.
	 *
	 * @return string The backend.
	 */
	public function getBackend(): string {
		return $this->backend;

	}//end getBackend()
}//end class
