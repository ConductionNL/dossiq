<?php

/**
 * Tests for reading the saved and previous object off an object event.
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

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The saved object comes from getObject(), else getNewObject(); the previous
 * one from getOldObject(); a create is always a change; schema membership is
 * by slug or a URL ending in it.
 *
 * @covers \OCA\Dossiq\Listener\Support\SavedObjectPayload
 * @uses \OCA\Dossiq\Listener\Support\SchemaMembership
 *
 * @spec openspec/specs/termijnbewaking-op-engine-timers/spec.md
 */
class SavedObjectPayloadEventTest extends TestCase {

	/**
	 * A created event's getObject() is the saved object, with its `@self` id lifted to the top.
	 *
	 * @return void
	 */
	public function testACreatedEventAnswersItsObjectAndNoPrevious(): void {
		$event = $this->event(getters: ['getObject' => ['@self' => ['id' => 'c-1'], 'status' => 'open']]);

		$this->assertSame(
			expected: ['@self' => ['id' => 'c-1'], 'status' => 'open', 'id' => 'c-1'],
			actual: $this->host()->saved(event: $event)
		);
		$this->assertNull(actual: $this->host()->previous(event: $event));
	}//end testACreatedEventAnswersItsObjectAndNoPrevious()

	/**
	 * An updated event's getNewObject() is the saved object and getOldObject() the previous one.
	 *
	 * @return void
	 */
	public function testAnUpdatedEventAnswersNewAndOld(): void {
		$event = $this->event(getters: [
			'getNewObject' => ['id' => 'u-1', 'status' => 'closed'],
			'getOldObject' => ['id' => 'u-1', 'status' => 'open'],
		]);

		$this->assertSame(expected: ['id' => 'u-1', 'status' => 'closed'], actual: $this->host()->saved(event: $event));
		$this->assertSame(expected: ['id' => 'u-1', 'status' => 'open'], actual: $this->host()->previous(event: $event));
	}//end testAnUpdatedEventAnswersNewAndOld()

	/**
	 * A getObject() that answers null falls through to getNewObject().
	 *
	 * @return void
	 */
	public function testANullObjectFallsThroughToTheNewObject(): void {
		$event = $this->event(getters: ['getObject' => null, 'getNewObject' => ['id' => 'n-1']]);

		$this->assertSame(expected: ['id' => 'n-1'], actual: $this->host()->saved(event: $event));
	}//end testANullObjectFallsThroughToTheNewObject()

	/**
	 * An event without object getters answers nothing either way.
	 *
	 * @return void
	 */
	public function testAnEventWithoutObjectsAnswersNothing(): void {
		$this->assertNull(actual: $this->host()->saved(event: new Event()));
		$this->assertNull(actual: $this->host()->previous(event: new Event()));
	}//end testAnEventWithoutObjectsAnswersNothing()

	/**
	 * A create always counts as a change; an update is unchanged only when every listed field is.
	 *
	 * @return void
	 */
	public function testUnchangedComparesOnlyTheListedFields(): void {
		$host = $this->host();
		$after = ['deadline' => '2026-10-20', 'tags' => ['a', 'b'], 'subject' => 'Nieuw'];

		$this->assertFalse(condition: $host->same(before: null, after: $after, fields: []));
		$before = ['deadline' => '2026-10-20', 'tags' => ['a', 'b'], 'subject' => 'Oud'];
		$this->assertTrue(condition: $host->same(before: $before, after: $after, fields: ['deadline', 'tags']));
		$this->assertFalse(condition: $host->same(before: ['deadline' => '2026-10-20', 'tags' => ['b', 'a']], after: $after, fields: ['deadline', 'tags']));
		$this->assertFalse(condition: $host->same(before: ['deadline' => '2026-10-21', 'tags' => ['a', 'b']], after: $after, fields: ['deadline']));
		$this->assertTrue(condition: $host->same(before: ['missing' => null], after: $after, fields: ['missing']));
		$this->assertFalse(condition: $host->same(before: ['deadline' => 20261020], after: $after, fields: ['deadline']));
	}//end testUnchangedComparesOnlyTheListedFields()

	/**
	 * Schema membership is by slug or by a URL ending in it; an empty configuration matches nothing.
	 *
	 * @return void
	 */
	public function testSchemaMembership(): void {
		$host = $this->host();

		$this->assertTrue(condition: $host->member(object: ['@self' => ['schema' => 'advice']], configured: 'advice'));
		$this->assertTrue(condition: $host->member(object: ['schema' => '/api/schemas/advice'], configured: 'advice'));
		$this->assertFalse(condition: $host->member(object: ['schema' => 'preadvice'], configured: 'advice'));
		$this->assertFalse(condition: $host->member(object: ['schema' => 'advice'], configured: ''));
	}//end testSchemaMembership()

	/**
	 * An event with the given getters answering the given values.
	 *
	 * @param array<string, mixed> $getters Getter name to its value.
	 *
	 * @return Event
	 */
	private function event(array $getters): Event {
		$object = ($getters['getObject'] ?? null);
		$new = ($getters['getNewObject'] ?? null);
		$old = ($getters['getOldObject'] ?? null);

		if (array_key_exists('getObject', $getters) === true && array_key_exists('getNewObject', $getters) === true) {
			return new class ($object, $new) extends Event {
				/**
				 * Constructor.
				 *
				 * @param mixed $object The object.
				 * @param mixed $new    The new object.
				 */
				public function __construct(private readonly mixed $object, private readonly mixed $new) {
					parent::__construct();
				}

				/**
				 * The object.
				 *
				 * @return mixed
				 */
				public function getObject(): mixed {
					return $this->object;
				}

				/**
				 * The new object.
				 *
				 * @return mixed
				 */
				public function getNewObject(): mixed {
					return $this->new;
				}
			};
		}

		if (array_key_exists('getObject', $getters) === true) {
			return new class ($object) extends Event {
				/**
				 * Constructor.
				 *
				 * @param mixed $object The object.
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
					return $this->object;
				}
			};
		}

		return new class ($new, $old) extends Event {
			/**
			 * Constructor.
			 *
			 * @param mixed $new The new object.
			 * @param mixed $old The old object.
			 */
			public function __construct(private readonly mixed $new, private readonly mixed $old) {
				parent::__construct();
			}

			/**
			 * The new object.
			 *
			 * @return mixed
			 */
			public function getNewObject(): mixed {
				return $this->new;
			}

			/**
			 * The old object.
			 *
			 * @return mixed
			 */
			public function getOldObject(): mixed {
				return $this->old;
			}
		};
	}//end event()

	/**
	 * A host for the trait, with its reads made reachable.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class {
			use SavedObjectPayload;

			/**
			 * The saved object.
			 *
			 * @param Event $event The event.
			 *
			 * @return array<string, mixed>|null
			 */
			public function saved(Event $event): ?array {
				return $this->savedObject(event: $event);
			}

			/**
			 * The previous object.
			 *
			 * @param Event $event The event.
			 *
			 * @return array<string, mixed>|null
			 */
			public function previous(Event $event): ?array {
				return $this->previousObject(event: $event);
			}

			/**
			 * Whether the fields are unchanged.
			 *
			 * @param array<string, mixed>|null $before The before.
			 * @param array<string, mixed>      $after  The after.
			 * @param string[]                  $fields The fields.
			 *
			 * @return bool
			 */
			public function same(?array $before, array $after, array $fields): bool {
				return $this->unchanged(before: $before, after: $after, fields: $fields);
			}

			/**
			 * Whether the object is in the schema.
			 *
			 * @param array<string, mixed> $object     The object.
			 * @param string               $configured The schema.
			 *
			 * @return bool
			 */
			public function member(array $object, string $configured): bool {
				return $this->inSchema(object: $object, configured: $configured);
			}
		};
	}//end host()
}//end class
