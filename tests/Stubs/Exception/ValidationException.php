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
 * DELIBERATE DIVERGENCE. The real constructor's fourth argument is an
 * `Opis\JsonSchema\Errors\ValidationError` and its `getErrors()` returns one.
 * Opis is not in dossiq's dependency tree at all, so mirroring that signature
 * here would mean stubbing Opis as well to describe a value dossiq never
 * reads: it passes `getMessage()` through, which is OpenRegister's own
 * wording. Message, code and previous are mirrored verbatim; the errors
 * argument is not reproduced. Add it here the day dossiq reads it.
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
use Throwable;

if (class_exists('\\OCA\\OpenRegister\\Exception\\ValidationException', false) === false) {
    /**
     * Thrown when an object does not validate against its schema.
     */
    class ValidationException extends Exception {

        /**
         * Constructor.
         *
         * @param string         $message  What failed, already naming the property.
         * @param int            $code     The error code.
         * @param Throwable|null $previous The exception that caused this one.
         */
        public function __construct(
            string $message,
            int $code = 0,
            ?Throwable $previous = null,
        ) {
            parent::__construct(message: $message, code: $code, previous: $previous);
        }
    }
}
