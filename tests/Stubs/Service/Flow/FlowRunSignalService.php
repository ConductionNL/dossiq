<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowRunSignalService.
 *
 * Signature-matched to the real seam on openregister main: `answerByUuid()`
 * and `answer()`, each taking a RunSignal — resolve, assignee guard, audit and
 * delivery in one call,
 * refusing with a typed FlowSignalRefused. The stub delivers unconditionally;
 * dossiq's tests mock this class and drive the refusal paths through the
 * exception, because the guard rule belongs to OpenRegister and is tested
 * there. Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunSignalService', false) === false) {
    /**
     * Minimal FlowRunSignalService stand-in.
     */
    class FlowRunSignalService {

        /**
         * Answer a suspended run by uuid, guarded.
         *
         * @param string    $runUuid The run to answer.
         * @param RunSignal $signal  What is said, by whom, to which node.
         *
         * @return FlowRun The parked run.
         */
        public function answerByUuid(string $runUuid, RunSignal $signal): FlowRun {
            return new FlowRun();
        }

        /**
         * Answer an already-resolved run, guarded.
         *
         * @param FlowRun   $run    The run to answer.
         * @param RunSignal $signal What is said, by whom, to which node.
         *
         * @return FlowRun The parked run.
         */
        public function answer(FlowRun $run, RunSignal $signal): FlowRun {
            return $run;
        }
    }
}
