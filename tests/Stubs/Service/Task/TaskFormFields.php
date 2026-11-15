<?php

/**
 * Test stub for OCA\OpenRegister\Service\Task\TaskFormFields.
 *
 * The schema, action and fields of a native task form, as OpenRegister
 * passes them to TaskForm.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

if (class_exists('\\OCA\\OpenRegister\\Service\\Task\\TaskFormFields', false) === false) {
    /**
     * Minimal TaskFormFields stand-in.
     */
    final class TaskFormFields {

        /**
         * Constructor.
         *
         * @param string                                           $schema The subject schema reference.
         * @param string|null                                      $action The lifecycle action, if any.
         * @param array<int, array{field: string, required: bool}> $fields The declared fields.
         */
        public function __construct(
            public readonly string $schema = '',
            public readonly ?string $action = null,
            public readonly array $fields = [],
        ) {
        }//end __construct()
    }//end class
}//end if
