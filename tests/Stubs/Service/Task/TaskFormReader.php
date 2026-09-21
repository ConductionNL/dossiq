<?php

/**
 * Test stub for OCA\OpenRegister\Service\Task\TaskFormReader.
 *
 * 🔴 EVERY METHOD THROWS. The real reader owns the rules dossiq consumes:
 * which kinds exist, which field lists are coherent, and which properties of a
 * live schema a form could actually render. A stub that answered any of those
 * would be a second opinion about somebody else's rules, and a dossiq test
 * driving it would pass on behaviour nothing ships. Throwing makes an
 * unmocked call fail loudly instead.
 *
 * Signatures mirror the real class exactly, so a `onlyMethods` double of it
 * refuses a method the real reader does not have.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use LogicException;

if (class_exists('\\OCA\\OpenRegister\\Service\\Task\\TaskFormReader', false) === false) {
    /**
     * Minimal TaskFormReader stand-in.
     */
    class TaskFormReader {

        /**
         * The declaration a step's flat config spells.
         *
         * @param array<string, mixed> $config The step configuration.
         *
         * @return TaskForm The declaration.
         */
        public function fromConfig(array $config): TaskForm {
            throw new LogicException(
                'TaskFormReader::fromConfig is OpenRegister\'s; double it rather than relying on this stub.'
            );
        }//end fromConfig()


        /**
         * The declaration a task record carries under `metadata.form`.
         *
         * @param array<string, mixed> $record The stored declaration.
         *
         * @return TaskForm The declaration.
         */
        public function fromRecord(array $record): TaskForm {
            throw new LogicException(
                'TaskFormReader::fromRecord is OpenRegister\'s; double it rather than relying on this stub.'
            );
        }//end fromRecord()


        /**
         * Refuse a declaration no performer could complete.
         *
         * @param TaskForm $form The declaration.
         *
         * @return void
         */
        public function validate(TaskForm $form): void {
            throw new LogicException(
                'TaskFormReader::validate is OpenRegister\'s; double it rather than relying on this stub.'
            );
        }//end validate()
    }//end class
}//end if
