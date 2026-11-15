<?php

/**
 * Tests for reading the first readable payload among an event's getters.
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

use JsonSerializable;
use OCA\Dossiq\Listener\Support\EventObjectPayload;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * firstOf() tries the getters in order, skipping one the event lacks and one
 * whose value reads as nothing, and answers null when none answers.
 *
 * @covers \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class EventObjectPayloadFirstOfTest extends TestCase {

	/**
	 * A missing getter and an unreadable value are skipped; the first readable one answers.
	 *
	 * @return void
	 */
	public function testTheFirstReadableGetterAnswers(): void {
		$event = $this->event();

		$this->assertSame(
			expected: ['id' => 'new'],
			actual: EventObjectPayload::firstOf(event: $event, methods: ['getMissing', 'getText', 'getNewObject', 'getObject'])
		);
		$this->assertSame(
			expected: ['id' => 'obj'],
			actual: EventObjectPayload::firstOf(event: $event, methods: ['getObject', 'getNewObject'])
		);
	}//end testTheFirstReadableGetterAnswers()

	/**
	 * No getters, or none that answers an array, answers null.
	 *
	 * @return void
	 */
	public function testNoReadableGetterAnswersNull(): void {
		$event = $this->event();

		$this->assertNull(actual: EventObjectPayload::firstOf(event: $event, methods: []));
		$this->assertNull(actual: EventObjectPayload::firstOf(event: $event, methods: ['getMissing', 'getText']));
	}//end testNoReadableGetterAnswersNull()

	/**
	 * A value is an array as it is, a serialisable entity as its array, anything else nothing.
	 *
	 * @return void
	 */
	public function testFromValueReadsEachShape(): void {
		$this->assertSame(expected: ['a' => 1], actual: EventObjectPayload::fromValue(object: ['a' => 1]));
		$this->assertSame(expected: ['id' => 'obj'], actual: EventObjectPayload::fromValue(object: $this->entity(data: ['id' => 'obj'])));
		$this->assertNull(actual: EventObjectPayload::fromValue(object: $this->entity(data: 'text')));
		$this->assertNull(actual: EventObjectPayload::fromValue(object: 42));
	}//end testFromValueReadsEachShape()

	/**
	 * An event with a text getter and two object getters.
	 *
	 * @return Event
	 */
	private function event(): Event {
		return new class ($this->entity(data: ['id' => 'obj'])) extends Event {
			/**
			 * Constructor.
			 *
			 * @param JsonSerializable $object The entity getObject() answers.
			 */
			public function __construct(private readonly JsonSerializable $object) {
				parent::__construct();
			}

			/**
			 * Not an object.
			 *
			 * @return string
			 */
			public function getText(): string {
				return 'not an object';
			}

			/**
			 * An array.
			 *
			 * @return array<string, string>
			 */
			public function getNewObject(): array {
				return ['id' => 'new'];
			}

			/**
			 * An entity.
			 *
			 * @return JsonSerializable
			 */
			public function getObject(): JsonSerializable {
				return $this->object;
			}
		};
	}//end event()

	/**
	 * A serialisable entity.
	 *
	 * @param mixed $data What it serialises to.
	 *
	 * @return JsonSerializable
	 */
	private function entity(mixed $data): JsonSerializable {
		return new class ($data) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $data What it serialises to.
			 */
			public function __construct(private readonly mixed $data) {
			}

			/**
			 * Serialise.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end entity()
}//end class
