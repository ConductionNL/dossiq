<?php

/**
 * Test stub for OCA\OpenRegister\Exception\ValidationException.
 *
 * OpenRegister throws this when a write does not satisfy the schema, with a
 * message that already names the offending property -- `Property 'checklist'
 * should match format 'uuid' but 'e2e-checklist' does not`. Dossiq catches it
 * BY TYPE in InspectionChecklistController::submitResult() to answer 400
 * instead of 500, and a catch clause cannot be exercised by a unit test
 * without a class to throw.
 *
 * THE SIGNATURE IS MIRRORED WHOLE, INCLUDING THE ARGUMENT DOSSIQ NEVER READS.
 * It shipped without the fourth argument and without `getErrors()` on the
 * reasoning that dossiq only passes `getMessage()` through, and
 * StubApiDriftTest rejected it: a stub that declares a narrower API than the
 * class it doubles is exactly the witness that goes green here and fatal
 * against a real OpenRegister. `Opis\JsonSchema\Errors\ValidationError` is
 * not in dossiq's dependency tree, but it does not have to be. The drift check
 * parses source rather than reflecting, PHP resolves a parameter type only
 * when a value is passed against it, and `null` satisfies a nullable type
 * without resolving anything.
 *
 * It must extend Exception as the real one does -- the real class is NOT a
 * RuntimeException, which is why it fell past the `catch (RuntimeException)`
 * arm and into the `catch (Throwable)` 500 in the first place.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Opis\JsonSchema\Errors\ValidationError;
use Throwable;

if (class_exists('\\OCA\\OpenRegister\\Exception\\ValidationException', false) === false) {
    /**
     * Thrown when an object does not validate against its schema.
     */
    class ValidationException extends Exception {

        /**
         * The structured validation errors, when the thrower had any.
         *
         * @var ValidationError|null
         */
        private readonly ?ValidationError $errors;

        /**
         * Constructor.
         *
         * @param string               $message  What failed, already naming the property.
         * @param int                  $code     The error code.
         * @param Throwable|null       $previous The exception that caused this one.
         * @param ValidationError|null $errors   The validator's own error tree.
         */
        public function __construct(
            string $message,
            int $code = 0,
            ?Throwable $previous = null,
            ?ValidationError $errors = null,
        ) {
            $this->errors = $errors;
            parent::__construct(message: $message, code: $code, previous: $previous);
        }

        /**
         * The validation errors.
         *
         * @return ValidationError|null The error tree, or null when none was supplied.
         */
        public function getErrors(): ?ValidationError {
            return $this->errors;
        }
    }
}
