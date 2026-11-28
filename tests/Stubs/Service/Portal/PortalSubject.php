<?php

/**
 * Test stub for OCA\OpenRegister\Service\Portal\PortalSubject.
 *
 * Mirrors the real constructor and partyReference(). Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

if (class_exists('\\OCA\\OpenRegister\\Service\\Portal\\PortalSubject', false) === false) {
    /**
     * A portal subject acting on its own tasks.
     */
    final class PortalSubject {
        /**
         * The audience.
         *
         * @var string
         */
        public readonly string $audience;

        /**
         * Constructor.
         *
         * @param string              $subjectRef The server-derived subject reference.
         * @param PortalSubjectClaims $claims     The audience, tenant, trust and session.
         */
        public function __construct(
            public readonly string $subjectRef,
            public readonly PortalSubjectClaims $claims = new PortalSubjectClaims(),
        ) {
            $this->audience = $claims->audience;
        }

        /**
         * The party reference this subject acts as.
         *
         * @return string `party:<subjectRef>`.
         */
        public function partyReference(): string {
            return 'party:' . $this->subjectRef;
        }
    }
}
