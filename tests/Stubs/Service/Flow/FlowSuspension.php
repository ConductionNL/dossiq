<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowSuspension.
 *
 * The real class extends RuntimeException — a node suspends a run by THROWING
 * this. The stub must extend it too: without that, static analysis of a node
 * that documents `@throws FlowSuspension` reports the tag as not-a-Throwable,
 * and any test asserting the suspension would be catching something that is not
 * an exception at all.
 *
 * Parameter ORDER matters and is not cosmetic. `$resumeAt` is first and the
 * reason second, so `new FlowSuspension('waiting')` passes a string where a
 * DateTime belongs. Mirroring the real signature is what makes a caller that
 * gets it wrong fail here rather than in production.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use RuntimeException;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowSuspension', false) === false) {
    /**
     * Minimal FlowSuspension stand-in.
     */
    class FlowSuspension extends RuntimeException {

        /**
         * Constructor.
         *
         * @param DateTime|null $resumeAt When the run may wake, or null to wait on a signal.
         * @param string        $reason   Why it is waiting.
         */
        public function __construct(
            private readonly ?DateTime $resumeAt = null,
            string $reason = 'suspended',
        ) {
            parent::__construct(message: $reason);
        }

        /**
         * When the run may resume.
         *
         * @return DateTime|null The resume time.
         */
        public function getResumeAt(): ?DateTime {
            return $this->resumeAt;
        }
    }
}
