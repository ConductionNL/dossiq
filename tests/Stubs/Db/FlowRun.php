<?php

/**
 * Test stub for OCA\OpenRegister\Db\FlowRun.
 *
 * Only the surface dossiq reads: a run's uuid and its context, which is where
 * the per-node resume slots live. Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

if (class_exists('\\OCA\\OpenRegister\\Db\\FlowRun', false) === false) {
    /**
     * Minimal FlowRun stand-in.
     */
    class FlowRun {

        /**
         * The run's uuid.
         *
         * @var string|null
         */
        private ?string $uuid = null;

        /**
         * The run's context, holding the resume slots.
         *
         * @var array|null
         */
        private ?array $context = null;

        /**
         * Set the uuid.
         *
         * @param string|null $uuid The uuid.
         *
         * @return void
         */
        public function setUuid(?string $uuid): void {
            $this->uuid = $uuid;
        }

        /**
         * The uuid.
         *
         * @return string|null The uuid.
         */
        public function getUuid(): ?string {
            return $this->uuid;
        }

        /**
         * Set the context.
         *
         * @param array|null $context The context.
         *
         * @return void
         */
        public function setContext(?array $context): void {
            $this->context = $context;
        }

        /**
         * The context.
         *
         * @return array|null The context.
         */
        public function getContext(): ?array {
            return $this->context;
        }
    }
}
