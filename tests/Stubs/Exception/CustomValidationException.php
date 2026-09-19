<?php

/**
 * Test stub for OCA\OpenRegister\Exception\CustomValidationException.
 *
 * The sibling of ValidationException: OpenRegister throws this one for the
 * rule checks it makes outside the JSON-Schema validator (reference integrity,
 * custom property rules), and it is just as much the caller's fault. Dossiq
 * catches both in the same arm, so both need a class a unit test can throw.
 *
 * Mirrors the real constructor verbatim -- `(string $message, array $errors)`
 * -- and the `getErrors(): array` accessor, which unlike its sibling's carries
 * no third-party type.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

if (class_exists('\\OCA\\OpenRegister\\Exception\\CustomValidationException', false) === false) {
    /**
     * Thrown when OpenRegister's own rule checks reject a write.
     */
    class CustomValidationException extends Exception {

        /**
         * Constructor.
         *
         * @param string                              $message What failed, for a human.
         * @param array<string, string|array<string>> $errors  Errors keyed by field name.
         */
        public function __construct(
            string $message,
            private readonly array $errors,
        ) {
            parent::__construct(message: $message);
        }

        /**
         * The validation errors, keyed by field name.
         *
         * @return array<string, string|array<string>> The errors.
         */
        public function getErrors(): array {
            return $this->errors;
        }
    }
}
