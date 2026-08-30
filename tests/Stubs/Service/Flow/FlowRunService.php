<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowRunService.
 *
 * Only `signal()`, the one call dossiq makes. Self-skips when the real class is
 * present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunService', false) === false) {
    /**
     * Minimal FlowRunService stand-in.
     */
    class FlowRunService {

        /**
         * The context key a resume payload arrives under.
         *
         * @var string
         */
        public const SIGNAL_CONTEXT_KEY = 'signal';

        /**
         * Deliver a resume signal.
         *
         * @param FlowRun $run     The run.
         * @param array   $payload The signal payload.
         *
         * @return FlowRun|null The advanced run.
         */
        public function signal(FlowRun $run, array $payload = []): ?FlowRun {
            return $run;
        }
    }
}
