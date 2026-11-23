<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar.
 *
 * Like openregister's class it is final, has a private constructor and is
 * built from a stored definition through fromArray(). It keeps the definition
 * and answers the working weekdays from it; it does not validate it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\Timer\\WorkingCalendar', false) === false) {
    /**
     * A working calendar built from its definition.
     */
    final class WorkingCalendar {
        /**
         * Constructor.
         *
         * @param array<string, mixed> $definition The `working-calendar` object data.
         */
        private function __construct(
            private readonly array $definition,
        ) {
        }//end __construct()

        /**
         * Build a calendar from its definition.
         *
         * @param array<string, mixed> $definition The `working-calendar` object data.
         *
         * @return self The calendar.
         */
        public static function fromArray(array $definition): self {
            return new self(definition: $definition);
        }//end fromArray()

        /**
         * The calendar's slug.
         *
         * @return string The slug.
         */
        public function getSlug(): string {
            return (string)($this->definition['slug'] ?? '');
        }//end getSlug()

        /**
         * The ISO weekdays (1 = Monday) the calendar works.
         *
         * @return array<int, int> The weekdays.
         */
        public function getWorkingWeekdays(): array {
            return array_map('intval', (array)($this->definition['workingWeekdays'] ?? []));
        }//end getWorkingWeekdays()

        /**
         * Whether the calendar works on this day.
         *
         * @param DateTimeInterface $moment The day.
         *
         * @return bool True on a working weekday that is not a closure.
         */
        public function isWorkingDay(DateTimeInterface $moment): bool {
            if (in_array((int)$moment->format('N'), $this->getWorkingWeekdays(), true) === false) {
                return false;
            }

            return in_array($moment->format('Y-m-d'), (array)($this->definition['nonWorkingDates'] ?? []), true) === false;
        }//end isWorkingDay()
    }//end class
}
