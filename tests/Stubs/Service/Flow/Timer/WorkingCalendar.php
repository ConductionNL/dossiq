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
         * @return bool True on a working weekday.
         */
        public function isWorkingDay(DateTimeInterface $moment): bool {
            return in_array((int)$moment->format('N'), $this->getWorkingWeekdays(), true);
        }//end isWorkingDay()
    }//end class
}
