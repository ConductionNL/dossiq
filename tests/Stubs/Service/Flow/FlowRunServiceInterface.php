<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowRunServiceInterface.
 *
 * Only the context keys dossiq reads. They live on the interface in
 * OpenRegister, and FlowRunService inherits them from it. Self-skips when the
 * real interface is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (interface_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunServiceInterface', false) === false) {
    /**
     * Minimal FlowRunServiceInterface stand-in.
     */
    interface FlowRunServiceInterface {

        /**
         * The context key a resume payload arrives under.
         *
         * @var string
         */
        public const SIGNAL_CONTEXT_KEY = 'signal';

        /**
         * The context key the run's acting identity travels under
         * (openregister#3332; value frozen at `runAs`).
         *
         * @var string
         */
        public const RUN_AS_CONTEXT_KEY = 'runAs';
    }
}
