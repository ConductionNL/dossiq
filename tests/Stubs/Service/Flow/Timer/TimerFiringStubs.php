<?php

/**
 * Test stubs for OCA\OpenRegister\Service\Flow\Timer\TimerFiring and EscalationNotice.
 *
 * Mirror the constructors of the value objects FlowTimerFiredEvent is built
 * from. Each self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\Timer\\TimerFiring', false) === false) {
    /**
     * What a business timer fired.
     */
    final class TimerFiring {
        public function __construct(
            public readonly string $kind,
            public readonly string $transition,
            public readonly ?string $rungKey = null,
        ) {
        }//end __construct()
    }//end class
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\Timer\\EscalationNotice', false) === false) {
    /**
     * Whom a timer fire addresses, how urgently, and with what.
     */
    final class EscalationNotice {
        /**
         * @param array<int, array<string, string>> $recipients The resolved addressees.
         */
        public function __construct(
            public readonly array $recipients = [],
            public readonly ?string $priority = null,
            public readonly ?string $message = null,
            public readonly ?string $consequence = null,
        ) {
        }//end __construct()
    }//end class
}
