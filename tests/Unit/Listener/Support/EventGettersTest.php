<?php

/**
 * EventGetters Unit Tests.
 *
 * Pins the safe getters: an absent getter or a wrongly typed answer reads as empty, and strings are trimmed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\EventGetters;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventGetters.
 *
 * @covers \OCA\Dossiq\Listener\Support\EventGetters
 */
class EventGettersTest extends TestCase {

	/**
	 * An event with typed and mistyped getters.
	 *
	 * @return Event
	 */
	private function event(): Event {
		return new class extends Event {
			public function getTags(): array {
				return ['a', 'b'];
			}

			public function getName(): string {
				return '  Intake  ';
			}

			public function getCount(): int {
				return 7;
			}

			public function getNothing(): mixed {
				return null;
			}
		};
	}//end event()

	/**
	 * arrayFrom returns an array answer and [] for anything else.
	 *
	 * @return void
	 */
	public function testArrayFrom(): void {
		$getters = new EventGetters();
		$event = $this->event();

		$this->assertSame(['a', 'b'], $getters->arrayFrom($event, 'getTags'));
		$this->assertSame([], $getters->arrayFrom($event, 'getName'));
		$this->assertSame([], $getters->arrayFrom($event, 'getNothing'));
		$this->assertSame([], $getters->arrayFrom($event, 'getMissing'));
	}//end testArrayFrom()

	/**
	 * stringFrom trims scalars, stringifies numbers, and is '' for anything else.
	 *
	 * @return void
	 */
	public function testStringFrom(): void {
		$getters = new EventGetters();
		$event = $this->event();

		$this->assertSame('Intake', $getters->stringFrom($event, 'getName'));
		$this->assertSame('7', $getters->stringFrom($event, 'getCount'));
		$this->assertSame('', $getters->stringFrom($event, 'getTags'));
		$this->assertSame('', $getters->stringFrom($event, 'getNothing'));
		$this->assertSame('', $getters->stringFrom($event, 'getMissing'));
	}//end testStringFrom()
}//end class
