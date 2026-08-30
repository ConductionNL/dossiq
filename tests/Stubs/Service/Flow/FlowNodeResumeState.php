<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowNodeResumeState.
 *
 * A node's own slot in the run's resume state: what it recorded when it
 * suspended, and — via nodeId() — which node it belongs to. A run accumulates
 * one slot PER NODE, which is why anything resuming a run must name the node.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowNodeResumeState', false) === false) {
    /**
     * Minimal FlowNodeResumeState stand-in.
     */
    class FlowNodeResumeState {

        /**
         * The context key the slot travels under.
         *
         * @var string
         */
        public const CONTEXT_KEY = 'resumeState';

        /**
         * Constructor.
         *
         * @param string $nodeId This node's id.
         * @param array  $values The slot's contents.
         */
        public function __construct(
            private readonly string $nodeId = 'node',
            private array $values = [],
        ) {
        }

        /**
         * This node's id within the graph.
         *
         * @return string The node id.
         */
        public function nodeId(): string {
            return $this->nodeId;
        }

        /**
         * Whether a key is held.
         *
         * @param string $key The key.
         *
         * @return boolean True when held.
         */
        public function has(string $key): bool {
            return array_key_exists($key, $this->values);
        }

        /**
         * Read a value.
         *
         * @param string $key     The key.
         * @param mixed  $default Returned when absent.
         *
         * @return mixed The value.
         */
        public function get(string $key, mixed $default = null): mixed {
            return ($this->values[$key] ?? $default);
        }

        /**
         * Merge values into the slot.
         *
         * @param array $values The values.
         *
         * @return void
         */
        public function merge(array $values): void {
            $this->values = array_merge($this->values, $values);
        }

        /**
         * Everything held.
         *
         * @return array The slot.
         */
        public function all(): array {
            return $this->values;
        }
    }
}
