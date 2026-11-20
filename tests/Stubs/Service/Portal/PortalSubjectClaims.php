<?php

/**
 * Test stub for OCA\OpenRegister\Service\Portal\PortalSubjectClaims.
 *
 * Mirrors the real constructor verbatim. Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

if (class_exists('\\OCA\\OpenRegister\\Service\\Portal\\PortalSubjectClaims', false) === false) {
    /**
     * The audience, tenant, trust and session a portal assertion carried.
     */
    final class PortalSubjectClaims {
        /**
         * Constructor.
         *
         * @param string $audience     The audience.
         * @param string $organisation The tenant.
         * @param string $trust        The trust level.
         * @param string $jti          The session id.
         */
        public function __construct(
            public readonly string $audience = '',
            public readonly string $organisation = '',
            public readonly string $trust = '',
            public readonly string $jti = '',
        ) {
        }
    }
}
