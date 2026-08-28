<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowResumeState.
 *
 * The run-level bag of per-node resume slots. dossiq reads only `CONTEXT_KEY`,
 * to find the slot that records which decision a suspended run is waiting on.
 *
 * Its absence was not a test failure — it was a PSALM failure
 * (`UndefinedClass`), because dossiq maps the whole OpenRegister namespace to
 * this directory. A class dossiq references but never stubs simply does not
 * exist as far as static analysis is concerned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowResumeState', false) === false) {
    /**
     * Minimal FlowResumeState stand-in.
     */
    class FlowResumeState {

        /**
         * The run-context key the per-node slots travel under.
         *
         * @var string
         */
        public const CONTEXT_KEY = 'resumeState';

        /**
         * Constructor.
         *
         * @param array $slots The per-node slots, keyed by node id.
         */
        public function __construct(
            private array $slots = [],
        ) {
        }

        /**
         * One node's slot.
         *
         * @param string $nodeId The node id.
         *
         * @return array The slot, or an empty array.
         */
        public function read(string $nodeId): array {
            return (array) ($this->slots[$nodeId] ?? []);
        }
    }
}
