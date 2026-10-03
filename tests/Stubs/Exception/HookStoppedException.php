<?php

/**
 * Test stub for OCA\OpenRegister\Exception\HookStoppedException.
 *
 * OpenRegister's MagicMapper raises this when a pre-persist listener stops the
 * event, carrying the listener's own error body through as `getErrors()`.
 * Dossiq catches it BY TYPE in `ZrcController::destroyCase()` so a case the
 * delete guard refused answers 409 with the guard's sentence instead of the
 * generic 400 every other delete failure gets, and a catch clause cannot be
 * exercised without a class to throw.
 *
 * The signature is mirrored whole, defaults included, per the discipline
 * StubApiDriftTest enforces: a stub that declares a narrower API than the
 * class it doubles is exactly the witness that goes green here and fatal
 * against a real OpenRegister. It extends Exception as the real one does.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

if (class_exists('\\OCA\\OpenRegister\\Exception\\HookStoppedException', false) === false) {
	/**
	 * Thrown when a schema hook stops event propagation.
	 */
	class HookStoppedException extends Exception {

		/**
		 * Validation errors from the hook.
		 *
		 * @var array<string, mixed>
		 */
		private readonly array $errors;

		/**
		 * Constructor.
		 *
		 * @param string $message Error message.
		 * @param array<string, mixed> $errors Hook validation errors.
		 * @param int $code Error code.
		 * @param Throwable|null $previous Previous exception.
		 */
		public function __construct(
			string $message = 'Operation blocked by schema hook',
			array $errors = [],
			int $code = 0,
			?Throwable $previous = null,
		) {
			$this->errors = $errors;
			parent::__construct(message: $message, code: $code, previous: $previous);
		}

		/**
		 * Get the hook validation errors.
		 *
		 * @return array<string, mixed>
		 */
		public function getErrors(): array {
			return $this->errors;
		}
	}
}
