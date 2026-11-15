<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\RunSignal.
 *
 * The value object FlowRunSignalService::answer() and answerByUuid() take:
 * what the signal says, who says it, and the node it addresses. Self-skips
 * when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\RunSignal', false) === false) {
    /**
     * Minimal RunSignal stand-in.
     */
    final class RunSignal {

        /**
         * @param array       $payload  What the signaller wants the run to know.
         * @param string|null $actorUid Who is answering; null means anonymous.
         * @param string|null $nodeId   The node the answer addresses, when known.
         */
        public function __construct(
            public readonly array $payload,
            public readonly ?string $actorUid,
            public readonly ?string $nodeId = null,
        ) {
        }
    }
}
