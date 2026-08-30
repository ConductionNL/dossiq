<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowRunContext.
 *
 * dossiq only reads the CONTEXT_RUN key off the run context, so only the
 * constants are needed here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunContext', false) === false) {
    /**
     * Minimal FlowRunContext stand-in.
     */
    class FlowRunContext {

        /**
         * Context key carrying the executing run's uuid.
         *
         * @var string
         */
        public const CONTEXT_RUN = 'x-openregister-attribution-run';

        /**
         * Context key carrying the run's step-sequence base.
         *
         * @var string
         */
        public const CONTEXT_BASE = 'x-openregister-attribution-base';
    }
}
