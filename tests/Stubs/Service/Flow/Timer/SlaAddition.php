<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\Timer\SlaAddition.
 *
 * Mirrors openregister's constructor: the start instant, the amount, the unit
 * and the resolved WorkingCalendar, which is a typed parameter, so an addition
 * built around anything else fails here as it does in production.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use RuntimeException;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\Timer\\SlaAddition', false) === false) {
    /**
     * An amount of business time to add to an instant, on one calendar.
     */
    final class SlaAddition {
        /**
         * Constructor.
         *
         * @param DateTimeImmutable    $start    The start instant.
         * @param float                $value    The amount; negative subtracts.
         * @param string               $unit     The unit.
         * @param WorkingCalendar|null $calendar The resolved calendar.
         */
        public function __construct(
            public readonly DateTimeImmutable $start,
            public readonly float $value,
            public readonly string $unit,
            private readonly ?WorkingCalendar $calendar,
        ) {
        }//end __construct()

        /**
         * The calendar, which a business unit cannot do without.
         *
         * @return WorkingCalendar The calendar.
         */
        public function calendar(): WorkingCalendar {
            if ($this->calendar === null) {
                throw new RuntimeException('No working calendar was given.');
            }

            return $this->calendar;
        }//end calendar()
    }//end class
}
