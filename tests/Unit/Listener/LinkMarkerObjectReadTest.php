<?php

/**
 * Tests for how LinkMarkerListener reads the saved object of an event.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\LinkMarkerListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The new object wins over the object; an array or a serialisable object is read, anything else is skipped.
 *
 * @covers \OCA\Dossiq\Listener\LinkMarkerListener
 * @covers \OCA\Dossiq\Listener\Support\ReadsEventObjects
 *
 * @spec openspec/specs/case-management/spec.md
 */
class LinkMarkerObjectReadTest extends TestCase {

	/**
	 * The events and what objectOf() reads from them.
	 *
	 * @return array<string, array{0: Event, 1: array<string, mixed>|null, 2: list<string>}>
	 */
	public static function events(): array {
		$serialisable = new class implements \JsonSerializable {
			/**
			 * The values.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['from' => 'entity'];
			}
		};

		return [
			'new object array' => [self::event(new: ['from' => 'new'], object: ['from' => 'object']), ['from' => 'new'], ['getNewObject']],
			'new object entity' => [self::event(new: $serialisable, object: ['from' => 'object']), ['from' => 'entity'], ['getNewObject']],
			'new object unreadable' => [self::event(new: 'x', object: ['from' => 'object']), ['from' => 'object'], ['getNewObject', 'getObject']],
			'only an object' => [self::event(object: ['from' => 'object']), ['from' => 'object'], ['getObject']],
			'nothing readable' => [self::event(new: new \stdClass(), object: 3), null, ['getNewObject', 'getObject']],
			'no getters' => [new Event(), null, []],
		];
	}//end events()

	/**
	 * What is read, and which getters were asked.
	 *
	 * @param Event                     $event  The event.
	 * @param array<string, mixed>|null $read   The object read.
	 * @param list<string>              $called The getters asked, in order.
	 *
	 * @dataProvider events
	 *
	 * @return void
	 */
	public function testTheSavedObjectIsRead(Event $event, ?array $read, array $called): void {
		$listener = (new ReflectionClass(objectOrClass: LinkMarkerListener::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(objectOrMethod: $listener, method: 'objectOf');

		$this->assertSame(expected: $read, actual: $method->invoke($listener, $event));
		$this->assertSame(expected: $called, actual: ($event->called ?? []));
	}//end testTheSavedObjectIsRead()

	/**
	 * An event with the given getters, recording which are asked.
	 *
	 * @param mixed $new    What getNewObject() answers, or null for no such getter.
	 * @param mixed $object What getObject() answers.
	 *
	 * @return Event
	 */
	private static function event(mixed $new = null, mixed $object = null): Event {
		if ($new === null) {
			return new class ($object) extends Event {
				/**
				 * The getters asked.
				 *
				 * @var list<string>
				 */
				public array $called = [];

				/**
				 * Constructor.
				 *
				 * @param mixed $object What getObject() answers.
				 */
				public function __construct(private readonly mixed $object) {
					parent::__construct();
				}

				/**
				 * The object.
				 *
				 * @return mixed
				 */
				public function getObject(): mixed {
					$this->called[] = 'getObject';
					return $this->object;
				}
			};
		}

		return new class ($new, $object) extends Event {
			/**
			 * The getters asked.
			 *
			 * @var list<string>
			 */
			public array $called = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $new    What getNewObject() answers.
			 * @param mixed $object What getObject() answers.
			 */
			public function __construct(private readonly mixed $new, private readonly mixed $object) {
				parent::__construct();
			}

			/**
			 * The new object.
			 *
			 * @return mixed
			 */
			public function getNewObject(): mixed {
				$this->called[] = 'getNewObject';
				return $this->new;
			}

			/**
			 * The object.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				$this->called[] = 'getObject';
				return $this->object;
			}
		};
	}//end event()
}//end class
