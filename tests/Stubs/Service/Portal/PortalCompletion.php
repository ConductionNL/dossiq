<?php

/**
 * Test stub for OCA\OpenRegister\Service\Portal\PortalCompletion.
 *
 * Mirrors the real constructor verbatim. Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

if (class_exists('\\OCA\\OpenRegister\\Service\\Portal\\PortalCompletion', false) === false) {
    /**
     * What a portal party submitted to complete a task.
     */
    final class PortalCompletion {
        /**
         * Constructor.
         *
         * @param array<string, mixed>             $answers The submitted answers.
         * @param string|null                      $comment The party's comment.
         * @param array<int, array<string, mixed>> $files   The uploads.
         * @param string                           $outcome The outcome to record.
         */
        public function __construct(
            public readonly array $answers = [],
            public readonly ?string $comment = null,
            public readonly array $files = [],
            public readonly string $outcome = 'submitted',
        ) {
        }
    }
}
