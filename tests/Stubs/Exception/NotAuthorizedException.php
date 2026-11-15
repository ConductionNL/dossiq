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
 * The class is mirrored whole, defaults included, per the discipline
 * StubApiDriftTest enforces. It extends Exception as the real one does.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

if (class_exists('\\OCA\\OpenRegister\\Exception\\NotAuthorizedException', false) === false) {
	/**
	 * Thrown when a user is not authorized to perform an action.
	 */
	class NotAuthorizedException extends Exception {
		/**
		 * The default message.
		 *
		 * @var string
		 */
		protected $message = 'You are not authorized to perform this action';

		/**
		 * The default code: 403 Forbidden.
		 *
		 * @var integer
		 */
		protected $code = 403;
	}//end class
}//end if
