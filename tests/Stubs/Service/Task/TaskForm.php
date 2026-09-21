<?php

/**
 * Test stub for OCA\OpenRegister\Service\Task\TaskForm.
 *
 * The declaration a task presents, as OpenRegister models it. dossiq never
 * builds one: it hands the step configuration to `TaskFormReader::fromConfig()`
 * and the result straight back to `TaskFormReader::validate()`. So the stub
 * carries the SHAPE and no behaviour, and a test that wanted behaviour out of
 * it would be testing a fake of somebody else's rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

if (class_exists('\\OCA\\OpenRegister\\Service\\Task\\TaskForm', false) === false) {
    /**
     * Minimal TaskForm stand-in.
     */
    class TaskForm {

        /**
         * The native kind: a list of fields on a schema.
         *
         * @var string
         */
        public const KIND_FIELDS = 'fields';

        /**
         * The bound Nextcloud Forms kind.
         *
         * @var string
         */
        public const KIND_EXTERNAL = 'external';

        /**
         * The two kinds, in the order the real class lists them.
         *
         * @var array<int, string>
         */
        public const KINDS = [self::KIND_FIELDS, self::KIND_EXTERNAL];


        /**
         * Constructor.
         *
         * @param string|null                                    $kind             The kind, or null for no form.
         * @param string                                         $schema           The subject schema reference.
         * @param string|null                                    $action           The lifecycle action, if any.
         * @param array<int, array{field: string, required: bool}> $fields          The declared fields.
         * @param int|null                                       $formId           The bound external form.
         * @param boolean                                        $requireChecklist Whether a checklist is required.
         */
        public function __construct(
            public readonly ?string $kind,
            public readonly string $schema = '',
            public readonly ?string $action = null,
            public readonly array $fields = [],
            public readonly ?int $formId = null,
            public readonly bool $requireChecklist = false,
        ) {
        }//end __construct()


        /**
         * Whether this declaration asks for anything.
         *
         * @return boolean True when a kind is set.
         */
        public function hasForm(): bool {
            return ($this->kind !== null && $this->kind !== '');
        }//end hasForm()
    }//end class
}//end if
