<?php

/**
 * Test stub for OCA\OpenRegister\Db\FlowRunMapper.
 *
 * Only the two reads dossiq performs. Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

if (class_exists('\\OCA\\OpenRegister\\Db\\FlowRunMapper', false) === false) {
    /**
     * Minimal FlowRunMapper stand-in.
     */
    class FlowRunMapper {

        /**
         * Find one run by uuid.
         *
         * @param string $uuid The run uuid.
         *
         * @return FlowRun The run.
         */
        public function findByUuid(string $uuid): FlowRun {
            return new FlowRun();
        }

        /**
         * The suspended runs for one subject.
         *
         * @param string  $subjectUuid The subject uuid.
         * @param integer $limit       Maximum rows.
         *
         * @return FlowRun[] The runs.
         */
        public function findSuspendedBySubject(string $subjectUuid, int $limit = 25): array {
            return [];
        }
    }
}
