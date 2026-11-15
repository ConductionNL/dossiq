<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\Timer\CalendarSpan.
 *
 * Mirrors openregister's constructor: two instants and the resolved
 * WorkingCalendar, which is a typed parameter, so a span built around anything
 * else fails here as it does in production.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\Timer\\CalendarSpan', false) === false) {
    /**
     * Two instants and the calendar they are measured on.
     */
    final class CalendarSpan {
        /**
         * The start.
         *
         * @var DateTimeImmutable
         */
        public readonly DateTimeImmutable $from;

        /**
         * The end.
         *
         * @var DateTimeImmutable
         */
        public readonly DateTimeImmutable $to;

        /**
         * Constructor.
         *
         * @param DateTimeInterface $from     The start.
         * @param DateTimeInterface $to       The end.
         * @param WorkingCalendar   $calendar The resolved calendar.
         */
        public function __construct(
            DateTimeInterface $from,
            DateTimeInterface $to,
            public readonly WorkingCalendar $calendar,
        ) {
            $this->from = DateTimeImmutable::createFromInterface($from);
            $this->to = DateTimeImmutable::createFromInterface($to);
        }//end __construct()
    }//end class
}
