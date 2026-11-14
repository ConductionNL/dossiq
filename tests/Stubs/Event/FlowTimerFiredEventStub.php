<?php

/**
 * Test stub for OCA\OpenRegister\Event\FlowTimerFiredEvent.
 *
 * Mirrors the REAL constructor and getter signatures of the engine event
 * (a stub that agrees with the caller cannot fail): the timer, a
 * TimerFiring (kind, named transition, rung key) and an optional
 * EscalationNotice (recipients, priority, message, consequence). Self-skips
 * when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Service\Flow\Timer\EscalationNotice;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use OCP\EventDispatcher\Event;

if (class_exists('\\OCA\\OpenRegister\\Event\\FlowTimerFiredEvent', false) === false) {
    /**
     * FlowTimerFiredEvent stand-in with the engine's own signature.
     */
    class FlowTimerFiredEvent extends Event {

        /**
         * Kinds of fire, as the engine declares them.
         */
        public const KIND_RUNG = 'rung';

        public const KIND_EXPIRY = 'expiry';

        private string $kind;

        private string $transition;

        private ?string $rungKey;

        private array $recipients;

        private ?string $priority;

        private ?string $message;

        private ?string $consequence;

        /**
         * Constructor, mirroring the engine's parameter names and order.
         *
         * @param FlowTimer $timer The timer that fired.
         * @param TimerFiring $firing The kind, the named transition raised and the rung key.
         * @param EscalationNotice|null $notice Recipients, priority, message and consequence.
         */
        public function __construct(
            private readonly FlowTimer $timer,
            TimerFiring $firing,
            ?EscalationNotice $notice = null,
        ) {
            parent::__construct();
            $notice = $notice ?? new EscalationNotice();
            $this->kind = $firing->kind;
            $this->transition = $firing->transition;
            $this->rungKey = $firing->rungKey;
            $this->recipients = $notice->recipients;
            $this->priority = $notice->priority;
            $this->message = $notice->message;
            $this->consequence = $notice->consequence;
        }//end __construct()

        /**
         * The timer that fired.
         *
         * @return FlowTimer
         */
        public function getTimer(): FlowTimer {
            return $this->timer;
        }//end getTimer()

        /**
         * Whether this was a rung or the expiry.
         *
         * @return string
         */
        public function getKind(): string {
            return $this->kind;
        }//end getKind()

        /**
         * The named transition raised.
         *
         * @return string
         */
        public function getTransition(): string {
            return $this->transition;
        }//end getTransition()

        /**
         * The rung key, for a rung fire.
         *
         * @return string|null
         */
        public function getRungKey(): ?string {
            return $this->rungKey;
        }//end getRungKey()

        /**
         * The resolved addressees.
         *
         * @return array
         */
        public function getRecipients(): array {
            return $this->recipients;
        }//end getRecipients()

        /**
         * The rung's priority.
         *
         * @return string|null
         */
        public function getPriority(): ?string {
            return $this->priority;
        }//end getPriority()

        /**
         * The message identity.
         *
         * @return string|null
         */
        public function getMessage(): ?string {
            return $this->message;
        }//end getMessage()
    }
}//end if
