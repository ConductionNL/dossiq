<?php

/**
 * Tests for reading an object update's payload.
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

use OCA\Dossiq\Listener\Support\ObjectUpdatePayload;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The payload reads accept arrays, serialisable objects and toArray() objects, and answer nothing for anything else.
 *
 * @covers \OCA\Dossiq\Listener\Support\ObjectUpdatePayload
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class ObjectUpdatePayloadTest extends TestCase {

	/**
	 * A value becomes an array when it is one or can make one.
	 *
	 * @return void
	 */
	public function testPayloadArrayReadsWhatCanBeRead(): void {
		$reader = $this->reader();

		$this->assertSame(expected: ['a' => 1], actual: $reader->read(value: ['a' => 1]));
		$this->assertNull(actual: $reader->read(value: 'text'));
		$this->assertNull(actual: $reader->read(value: new \stdClass()));
		$this->assertSame(expected: ['b' => 2], actual: $reader->read(value: new class {
			/**
			 * The values.
			 *
			 * @return array<string, int>
			 */
			public function toArray(): array {
				return ['b' => 2];
			}
		},
		));
		$this->assertSame(expected: ['c' => 3], actual: $reader->read(value: new class {
			/**
			 * Not an array.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}

			/**
			 * The values.
			 *
			 * @return array<string, int>
			 */
			public function toArray(): array {
				return ['c' => 3];
			}
		},
		));
	}//end testPayloadArrayReadsWhatCanBeRead()

	/**
	 * An event without an object or an old object answers none.
	 *
	 * @return void
	 */
	public function testAnEventWithoutObjectsAnswersNone(): void {
		$reader = $this->reader();
		$event = new class extends Event {
			/**
			 * Not an object.
			 *
			 * @return string
			 */
			public function getNewObject(): string {
				return 'nothing';
			}
		};

		$this->assertNull(actual: $reader->updated(event: $event));
		$this->assertSame(expected: '', actual: $reader->previous(event: $event));
	}//end testAnEventWithoutObjectsAnswersNone()

	/**
	 * A class using the reads, with them made reachable.
	 *
	 * @return object
	 */
	private function reader(): object {
		return new class {
			use ObjectUpdatePayload;

			/**
			 * The payload array.
			 *
			 * @param mixed $value The value.
			 *
			 * @return array<string, mixed>|null
			 */
			public function read(mixed $value): ?array {
				return $this->payloadArray(value: $value);
			}

			/**
			 * The updated object.
			 *
			 * @param Event $event The event.
			 *
			 * @return array<string, mixed>|null
			 */
			public function updated(Event $event): ?array {
				return $this->updatedObject(event: $event);
			}

			/**
			 * The previous status.
			 *
			 * @param Event $event The event.
			 *
			 * @return string
			 */
			public function previous(Event $event): string {
				return $this->previousStatus(event: $event);
			}
		};
	}//end reader()
}//end class
