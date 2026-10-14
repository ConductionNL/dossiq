<?php

/**
 * Test stub for OCA\OpenRegister\Exception\NotAuthorizedException.
 *
 * OpenRegister's permission check raises this when the caller may not read,
 * write or delete an object. Dossiq tells it apart from a store that could not
 * be reached: a ZGW rule lookup treats a refused read as an object it cannot
 * use, not as a check that could not run, and a ZGW delete answers 403 for it
 * rather than 503. Neither can be exercised without a class to throw.
 *
 * The signature is mirrored whole, defaults included, per the discipline
 * StubApiDriftTest enforces. It extends Exception as the real one does.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

if (class_exists('\\OCA\\OpenRegister\\Exception\\NotAuthorizedException', false) === false) {
	/**
	 * Thrown when a user is not authorized to perform an action.
	 */
	class NotAuthorizedException extends Exception {
		/**
		 * Constructor.
		 *
		 * @param string $message The error message describing authorization failure
		 * @param int $code The error code (default: 403 Forbidden)
		 * @param Throwable|null $previous The previous exception that caused this one
		 */
		public function __construct(
			string $message = 'You are not authorized to perform this action',
			int $code = 403,
			?Throwable $previous = null,
		) {
			parent::__construct(message: $message, code: $code, previous: $previous);
		}//end __construct()
	}//end class
}//end if
