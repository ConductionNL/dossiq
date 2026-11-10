<?php

/**
 * Tests for reading the object a created-object event carries.
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
use OCA\Dossiq\Listener\BezwaarLegalHoldListener;
use OCA\Dossiq\Listener\CasePhaseTermListener;
use OCA\Dossiq\Listener\ContactMomentPipelinqListener;
use OCA\Dossiq\Listener\ContactMomentTimelineListener;
use OCA\Dossiq\Listener\CustodyCaseCreatedListener;
use OCA\Dossiq\Listener\PortalMessageTimelineListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every listener reads the event's object the same way: an array as it is, a
 * serialisable entity as its array, anything else as nothing.
 *
 * @coversNothing
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class EventObjectPayloadTest extends TestCase {

	/**
	 * Each listener and its private read.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function listeners(): array {
		return [
			'legal hold' => [BezwaarLegalHoldListener::class, 'extractObject'],
			'phase term' => [CasePhaseTermListener::class, 'extractObject'],
			'contact moment pipelinq' => [ContactMomentPipelinqListener::class, 'payload'],
			'contact moment timeline' => [ContactMomentTimelineListener::class, 'payload'],
			'custody' => [CustodyCaseCreatedListener::class, 'extractObject'],
			'portal message timeline' => [PortalMessageTimelineListener::class, 'payload'],
		];
	}//end listeners()

	/**
	 * The listener's read answers every event shape.
	 *
	 * @param class-string $class  The listener.
	 * @param string       $method Its private read.
	 *
	 * @dataProvider listeners
	 *
	 * @return void
	 */
	public function testTheListenerReadsEveryShape(string $class, string $method): void {
		$listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$read = new ReflectionMethod($class, $method);

		foreach ($this->shapes() as $name => [$event, $expected]) {
			$this->assertSame($expected, $read->invoke($listener, $event), $name);
		}
	}//end testTheListenerReadsEveryShape()

	/**
	 * Events and the array each one carries.
	 *
	 * @return array<string, array{0: Event, 1: array<string, mixed>|null}>
	 */
	private function shapes(): array {
		return [
			'an array' => [$this->carrying(object: ['id' => 'o-1']), ['id' => 'o-1']],
			'an empty array' => [$this->carrying(object: []), []],
			'a serialisable entity' => [$this->carrying(object: $this->entity(data: ['id' => 'e-1'])), ['id' => 'e-1']],
			'an entity serialising to a string' => [$this->carrying(object: $this->entity(data: 'e-1')), null],
			'a plain object' => [$this->carrying(object: new \stdClass()), null],
			'a string' => [$this->carrying(object: 'o-1'), null],
			'null' => [$this->carrying(object: null), null],
			'an event without an object' => [new Event(), null],
		];
	}//end shapes()

	/**
	 * An event whose getObject() returns the value.
	 *
	 * @param mixed $object The value.
	 *
	 * @return Event
	 */
	private function carrying(mixed $object): Event {
		return new class ($object) extends Event {
			/**
			 * Constructor.
			 *
			 * @param mixed $object The value.
			 */
			public function __construct(private readonly mixed $object) {
				parent::__construct();
			}

			/**
			 * The value.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->object;
			}
		};
	}//end carrying()

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
