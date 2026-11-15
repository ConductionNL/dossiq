<?php

/**
 * Tests for recognising an update that moves an object into a status.
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
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only an update of an object in the configured schema that crosses into the
 * status counts; the id comes from `@self`, then `id`, then `uuid`.
 *
 * @covers \OCA\Dossiq\Listener\Support\ObjectUpdatePayload
 * @uses \OCA\Dossiq\Listener\Support\ReadsEventObjects
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class ObjectUpdatePayloadStatusEntryTest extends TestCase {

	/**
	 * The rule every case below checks.
	 */
	private const RULE = ['schemaKey' => 'hearing_schema', 'status' => 'gepland'];

	/**
	 * The settings the trait reads the schema from.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Configure the schema key.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['hearing_schema', '', 'hearing'],
				['unset_schema', '', ''],
			]
		);
	}//end setUp()

	/**
	 * An update into the status of an object in the schema answers its `@self` id.
	 *
	 * @return void
	 */
	public function testAnUpdateIntoTheStatusAnswersTheObjectId(): void {
		$event = new ObjectUpdatedEvent(
			$this->entity(uuid: 'h-1', schema: 'hearing', data: ['status' => 'gepland']),
			$this->entity(uuid: 'h-1', schema: 'hearing', data: ['status' => 'concept'])
		);

		$this->assertSame(expected: 'h-1', actual: $this->host()->entering(event: $event, rule: self::RULE));
	}//end testAnUpdateIntoTheStatusAnswersTheObjectId()

	/**
	 * A schema URL that ends in the configured slug counts; one that merely contains it does not.
	 *
	 * @return void
	 */
	public function testASchemaUrlEndingInTheSlugCounts(): void {
		$host = $this->host();

		$this->assertSame(
			expected: 'h-2',
			actual: $host->entering(
				event: new ObjectUpdatedEvent($this->entity(uuid: 'h-2', schema: 'https://or.example/schemas/hearing', data: ['status' => 'gepland'])),
				rule: self::RULE
			)
		);
		$this->assertSame(
			expected: '',
			actual: $host->entering(
				event: new ObjectUpdatedEvent($this->entity(uuid: 'h-3', schema: 'https://or.example/schemas/prehearing', data: ['status' => 'gepland'])),
				rule: self::RULE
			)
		);
	}//end testASchemaUrlEndingInTheSlugCounts()

	/**
	 * An update without a previous object is a transition: the previous status reads as ''.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnOldObjectCountsAsEntering(): void {
		$event = new ObjectUpdatedEvent($this->entity(uuid: 'h-4', schema: 'hearing', data: ['status' => 'gepland']));

		$this->assertSame(expected: '', actual: $this->host()->previous(event: $event));
		$this->assertSame(expected: 'h-4', actual: $this->host()->entering(event: $event, rule: self::RULE));
	}//end testAnUpdateWithoutAnOldObjectCountsAsEntering()

	/**
	 * An object that already had the status, one in another status, or one in another schema answers ''.
	 *
	 * @return void
	 */
	public function testAnythingButTheTransitionAnswersNothing(): void {
		$host = $this->host();

		$already = new ObjectUpdatedEvent(
			$this->entity(uuid: 'h-5', schema: 'hearing', data: ['status' => 'gepland']),
			$this->entity(uuid: 'h-5', schema: 'hearing', data: ['status' => 'gepland'])
		);
		$other = new ObjectUpdatedEvent(
			$this->entity(uuid: 'h-6', schema: 'hearing', data: ['status' => 'afgerond']),
			$this->entity(uuid: 'h-6', schema: 'hearing', data: ['status' => 'concept'])
		);
		$foreign = new ObjectUpdatedEvent(
			$this->entity(uuid: 'h-7', schema: 'zaak', data: ['status' => 'gepland']),
			$this->entity(uuid: 'h-7', schema: 'zaak', data: ['status' => 'concept'])
		);

		$this->assertSame(expected: 'gepland', actual: $host->previous(event: $already));
		$this->assertSame(expected: '', actual: $host->entering(event: $already, rule: self::RULE));
		$this->assertSame(expected: '', actual: $host->entering(event: $other, rule: self::RULE));
		$this->assertSame(expected: '', actual: $host->entering(event: $foreign, rule: self::RULE));
	}//end testAnythingButTheTransitionAnswersNothing()

	/**
	 * An unconfigured schema key matches nothing, not even an object without a schema.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaMatchesNothing(): void {
		$event = new ObjectUpdatedEvent($this->entity(uuid: 'h-8', schema: 'hearing', data: ['status' => 'gepland']));

		$this->assertSame(
			expected: '',
			actual: $this->host()->entering(event: $event, rule: ['schemaKey' => 'unset_schema', 'status' => 'gepland'])
		);
		$this->assertFalse(condition: $this->host()->inSchema(object: ['status' => 'gepland'], schemaKey: 'hearing_schema'));
	}//end testAnUnconfiguredSchemaMatchesNothing()

	/**
	 * An event that is not an object update answers '' even when it carries a matching object.
	 *
	 * @return void
	 */
	public function testAnotherEventAnswersNothing(): void {
		$entity = $this->entity(uuid: 'h-9', schema: 'hearing', data: ['status' => 'gepland']);
		$event = new class ($entity) extends Event {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity $entity The object.
			 */
			public function __construct(private readonly ObjectEntity $entity) {
				parent::__construct();
			}

			/**
			 * The object.
			 *
			 * @return ObjectEntity
			 */
			public function getNewObject(): ObjectEntity {
				return $this->entity;
			}
		};

		$this->assertSame(expected: '', actual: $this->host()->entering(event: $event, rule: self::RULE));
	}//end testAnotherEventAnswersNothing()

	/**
	 * Without a `@self` id the top-level id answers, and without that the uuid.
	 *
	 * @return void
	 */
	public function testTheIdFallsBackToIdThenUuid(): void {
		$host = $this->host();

		$byId = new ObjectUpdatedEvent($this->serialising(data: ['schema' => 'hearing', 'status' => 'gepland', 'id' => 'top', 'uuid' => 'u-1']));
		$byUuid = new ObjectUpdatedEvent($this->serialising(data: ['schema' => 'hearing', 'status' => 'gepland', 'uuid' => 'u-2']));
		$none = new ObjectUpdatedEvent($this->serialising(data: ['schema' => 'hearing', 'status' => 'gepland']));

		$this->assertSame(expected: 'top', actual: $host->entering(event: $byId, rule: self::RULE));
		$this->assertSame(expected: 'u-2', actual: $host->entering(event: $byUuid, rule: self::RULE));
		$this->assertSame(expected: '', actual: $host->entering(event: $none, rule: self::RULE));
	}//end testTheIdFallsBackToIdThenUuid()

	/**
	 * The schema is read from `@self.schema`, `@self.schemaSlug`, `schema` and `_schemaSlug`, in that order.
	 *
	 * @return void
	 */
	public function testTheSchemaIsReadFromEveryPlaceInOrder(): void {
		$host = $this->host();

		$this->assertSame(
			expected: 'a',
			actual: $host->named(object: ['@self' => ['schema' => 'a', 'schemaSlug' => 'b'], 'schema' => 'c', '_schemaSlug' => 'd'])
		);
		$this->assertSame(expected: 'b', actual: $host->named(object: ['@self' => ['schemaSlug' => 'b'], 'schema' => 'c']));
		$this->assertSame(expected: 'c', actual: $host->named(object: ['@self' => [], 'schema' => 'c', '_schemaSlug' => 'd']));
		$this->assertSame(expected: 'd', actual: $host->named(object: ['_schemaSlug' => 'd']));
		$this->assertSame(expected: '', actual: $host->named(object: []));
	}//end testTheSchemaIsReadFromEveryPlaceInOrder()

	/**
	 * An entity in a schema with data.
	 *
	 * @param string               $uuid   The uuid.
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An entity that serialises to exactly the given array.
	 *
	 * @param array<string, mixed> $data What it serialises to.
	 *
	 * @return ObjectEntity
	 */
	private function serialising(array $data): ObjectEntity {
		return new class ($data) extends ObjectEntity {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data What it serialises to.
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end serialising()

	/**
	 * A class using the reads, with them made reachable.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class ($this->settings) {
			use ObjectUpdatePayload;

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settingsService The settings.
			 */
			public function __construct(private readonly SettingsService $settingsService) {
			}

			/**
			 * The object entering the status.
			 *
			 * @param Event                 $event The event.
			 * @param array<string, string> $rule  The rule.
			 *
			 * @return string
			 */
			public function entering(Event $event, array $rule): string {
				return $this->objectEnteringStatus(event: $event, rule: $rule);
			}

			/**
			 * Whether the object is in the configured schema.
			 *
			 * @param array<string, mixed> $object    The object.
			 * @param string               $schemaKey The config key.
			 *
			 * @return bool
			 */
			public function inSchema(array $object, string $schemaKey): bool {
				return $this->inConfiguredSchema(object: $object, schemaKey: $schemaKey);
			}

			/**
			 * The schema named anywhere.
			 *
			 * @param array<string, mixed> $object The object.
			 *
			 * @return string
			 */
			public function named(array $object): string {
				return $this->schemaNamedAnywhere(object: $object);
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
	}//end host()
}//end class
