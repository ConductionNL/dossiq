<?php

/**
 * SerialisedObject Unit Tests.
 *
 * Pins how an event value becomes an array: arrays as is, jsonSerialize before toArray, and nothing for scalars or non-array answers.
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

use OCA\Dossiq\Listener\Support\SerialisedObject;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for SerialisedObject.
 *
 * @covers \OCA\Dossiq\Listener\Support\SerialisedObject
 */
class SerialisedObjectTest extends TestCase {

	/**
	 * An array is returned unchanged; a scalar, null or plain object is not an object.
	 *
	 * @return void
	 */
	public function testArraysPassAndScalarsDoNot(): void {
		$this->assertSame(['id' => 1], SerialisedObject::of(['id' => 1]));
		$this->assertSame([], SerialisedObject::of([]));
		$this->assertNull(SerialisedObject::of('text'));
		$this->assertNull(SerialisedObject::of(null));
		$this->assertNull(SerialisedObject::of(new stdClass()));
	}//end testArraysPassAndScalarsDoNot()

	/**
	 * jsonSerialize wins over toArray; toArray is the fallback.
	 *
	 * @return void
	 */
	public function testJsonSerializeIsPreferredOverToArray(): void {
		$both = new class {
			public function jsonSerialize(): array {
				return ['from' => 'json'];
			}

			public function toArray(): array {
				return ['from' => 'array'];
			}
		};
		$arrayOnly = new class {
			public function toArray(): array {
				return ['from' => 'array'];
			}
		};

		$this->assertSame(['from' => 'json'], SerialisedObject::of($both));
		$this->assertSame(['from' => 'array'], SerialisedObject::of($arrayOnly));
	}//end testJsonSerializeIsPreferredOverToArray()

	/**
	 * A serialiser answering a non-array falls through to toArray, then to null.
	 *
	 * @return void
	 */
	public function testANonArrayAnswerIsNotAnObject(): void {
		$stringJson = new class {
			public function jsonSerialize(): string {
				return 'x';
			}

			public function toArray(): array {
				return ['from' => 'array'];
			}
		};
		$nothing = new class {
			public function toArray(): string {
				return 'x';
			}
		};

		$this->assertSame(['from' => 'array'], SerialisedObject::of($stringJson));
		$this->assertNull(SerialisedObject::of($nothing));
	}//end testANonArrayAnswerIsNotAnObject()

	/**
	 * fromEvent tries the getters in order, skips missing and empty ones, and
	 * returns the first that yields an object.
	 *
	 * @return void
	 */
	public function testFromEventTakesTheFirstGetterThatYields(): void {
		$event = new class extends Event {
			public function getOld(): ?array {
				return null;
			}

			public function getNew(): array {
				return ['id' => 'new'];
			}

			public function getOther(): array {
				return ['id' => 'other'];
			}
		};

		$this->assertSame(['id' => 'new'], SerialisedObject::fromEvent($event, ['getMissing', 'getOld', 'getNew', 'getOther']));
		$this->assertSame(['id' => 'other'], SerialisedObject::fromEvent($event, ['getOther', 'getNew']));
		$this->assertNull(SerialisedObject::fromEvent($event, ['getMissing', 'getOld']));
		$this->assertNull(SerialisedObject::fromEvent(new Event(), ['getObject']));
	}//end testFromEventTakesTheFirstGetterThatYields()
}//end class
