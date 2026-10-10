<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registry;

use OCA\Dossiq\Listener\RequesterRegistrySubscriptionListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Registry\RequesterRegistrySubscription;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * contacts-domain 4.3 (Tier B B22): a case's requester is kept current from
 * BRP or KvK through OpenRegister's registry subscriptions.
 *
 * The OpenRegister double carries the REAL signatures of
 * RegistrySubscriptionService on development (openregister#3656):
 * annotationFor(Schema), stateFor(string), requestSubscription(ObjectEntity,
 * Schema). Its annotationFor() reads `x-openregister-registry` off the schema
 * the way the real one does, and the schemas are the shipped fragment
 * 25-brp-kvk.json, so a fragment without the annotation fails here.
 *
 * @spec openspec/changes/contacts-domain/tasks.md#4-integrations
 */
class RequesterRegistrySubscriptionTest extends TestCase {

	/** @var object The registry double. */
	private object $registry;

	/** @var array<string, ObjectEntity> Objects by uuid. */
	private array $objects = [];

	/**
	 * A registry double, a schema mapper over the shipped fragment, and an object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new class {
			/** @var array<string, array<string, mixed>> */
			public array $states = [];

			/** @var array<int, string> */
			public array $requested = [];

			/**
			 * @param object $schema The schema.
			 *
			 * @return array<string, mixed>|null The annotation.
			 */
			public function annotationFor(object $schema): ?array {
				$annotation = ($schema->definition['x-openregister-registry'] ?? null);

				return is_array($annotation) === true ? $annotation : null;
			}

			/**
			 * @param string $objectUuid The object.
			 *
			 * @return array<string, mixed>|null The mirror.
			 */
			public function stateFor(string $objectUuid): ?array {
				return ($this->states[$objectUuid] ?? null);
			}

			/**
			 * @param ObjectEntity $object The object.
			 * @param object $schema The schema.
			 *
			 * @return object The row.
			 */
			public function requestSubscription(ObjectEntity $object, object $schema): object {
				$annotation = $this->annotationFor($schema);
				if (($object->getObject()[$annotation['identity']] ?? '') === '') {
					throw new \InvalidArgumentException('Object has no value for identity property "' . $annotation['identity'] . '".');
				}

				$this->requested[] = (string)$object->getUuid();

				return new \stdClass();
			}
		};
	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @return RequesterRegistrySubscription The service.
	 */
	private function service(): RequesterRegistrySubscription {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/25-brp-kvk.json'),
			true
		);
		$schemas = new class($fragment['components']['schemas']) {
			/**
			 * @param array<string, array<string, mixed>> $definitions The shipped schemas.
			 */
			public function __construct(private array $definitions) {
			}

			/**
			 * @param string|int $id The schema id (a slug in this double).
			 *
			 * @return object The schema.
			 */
			public function find(string|int $id): object {
				$schema = new \stdClass();
				$schema->definition = ($this->definitions[(string)$id] ?? []);

				return $schema;
			}
		};
		$objects = new class($this->objects) {
			/**
			 * @param array<string, ObjectEntity> $objects By uuid.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * @param string $id The uuid.
			 *
			 * @return ObjectEntity|null The object.
			 */
			public function find(string $id): ?ObjectEntity {
				return ($this->objects[$id] ?? null);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			fn (string $class): ?object => match ($class) {
				RequesterRegistrySubscription::REGISTRY_SERVICE => $this->registry,
				RequesterRegistrySubscription::SCHEMA_MAPPER => $schemas,
				default => null,
			}
		);

		return new RequesterRegistrySubscription($settings, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * An object in a schema, by uuid.
	 *
	 * @param string $uuid The uuid.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema($schema);
		$entity->setUuid($uuid);
		$this->objects[$uuid] = $entity;

		return $entity;
	}//end object()

	/**
	 * Both shipped schemas declare the registry annotation OpenRegister validates.
	 *
	 * @return void
	 */
	public function testTheShippedSchemasAreRegistryBacked(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/25-brp-kvk.json'), true);
		$schemas = $fragment['components']['schemas'];

		foreach (['brpPerson' => ['brp', 'citizenServiceNumber'], 'kvkCompany' => ['kvk', 'kvkNumber']] as $slug => [$registry, $identity]) {
			$annotation = $schemas[$slug]['x-openregister-registry'] ?? null;
			$this->assertIsArray($annotation, "{$slug} declares no x-openregister-registry");
			$this->assertSame($registry, $annotation['registry'], 'integriq answers to brp and kvk (BrpVolgindicatieProvider, KvkMutatieProvider)');
			$this->assertSame($identity, $annotation['identity']);
			$this->assertNotEmpty($annotation['owned']);
			foreach ([$identity, ...$annotation['owned']] as $property) {
				$this->assertArrayHasKey($property, $schemas[$slug]['properties'], "OpenRegister refuses an undeclared {$property} at schema save");
			}

			$this->assertNotContains($identity, $annotation['owned'], 'The identity is how the update finds the row; the registry does not rewrite it.');
		}
	}//end testTheShippedSchemasAreRegistryBacked()

	/**
	 * A BRP person named as requester is subscribed.
	 *
	 * @return void
	 */
	public function testABrpRequesterIsSubscribed(): void {
		$this->object('p-1', 'brpPerson', ['citizenServiceNumber' => '999993653']);

		$this->assertSame(['requested' => true, 'reason' => ''], $this->service()->subscribe(objectUuid: 'p-1'));
		$this->assertSame(['p-1'], $this->registry->requested);
	}//end testABrpRequesterIsSubscribed()

	/**
	 * A requester already subscribed is not subscribed again.
	 *
	 * @return void
	 */
	public function testALiveSubscriptionIsLeftAlone(): void {
		$this->object('p-1', 'brpPerson', ['citizenServiceNumber' => '999993653']);
		$this->registry->states['p-1'] = ['state' => 'active'];

		$this->assertFalse($this->service()->subscribe(objectUuid: 'p-1')['requested']);
		$this->assertSame([], $this->registry->requested, 'Re-requesting would re-subscribe the person at the BRP on every save.');
	}//end testALiveSubscriptionIsLeftAlone()

	/**
	 * An ended subscription is requested again when a case names the person.
	 *
	 * @return void
	 */
	public function testAnEndedSubscriptionIsRequestedAgain(): void {
		$this->object('k-1', 'kvkCompany', ['kvkNumber' => '69599084']);
		$this->registry->states['k-1'] = ['state' => 'ended'];

		$this->assertTrue($this->service()->subscribe(objectUuid: 'k-1')['requested']);
	}//end testAnEndedSubscriptionIsRequestedAgain()

	/**
	 * A record no registry owns is not subscribed, and says so.
	 *
	 * @return void
	 */
	public function testARecordNoRegistryOwnsIsNotSubscribed(): void {
		$this->object('c-1', 'contact', ['name' => 'Sanne']);

		$answer = $this->service()->subscribe(objectUuid: 'c-1');

		$this->assertFalse($answer['requested']);
		$this->assertSame('the requester is not kept by a source register', $answer['reason']);
	}//end testARecordNoRegistryOwnsIsNotSubscribed()

	/**
	 * A person without a BSN is refused by OpenRegister, and the refusal is answered, not thrown.
	 *
	 * @return void
	 */
	public function testARefusalIsAnsweredNotThrown(): void {
		$this->object('p-2', 'brpPerson', ['name' => ['geslachtsnaam' => 'Vries']]);

		$answer = $this->service()->subscribe(objectUuid: 'p-2');

		$this->assertFalse($answer['requested']);
		$this->assertStringContainsString('citizenServiceNumber', $answer['reason']);
	}//end testARefusalIsAnsweredNotThrown()

	/**
	 * Without OpenRegister's registry service nothing is asked.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterWithoutTheCapabilityIsSaid(): void {
		$settings = $this->createMock(SettingsService::class);
		$service = new RequesterRegistrySubscription($settings, $this->createMock(LoggerInterface::class));

		$this->assertSame('this OpenRegister has no registry subscriptions', $service->subscribe(objectUuid: 'p-1')['reason']);
	}//end testAnOpenRegisterWithoutTheCapabilityIsSaid()

	/**
	 * The listener subscribes a NEW requester only, on create and on update.
	 *
	 * @return void
	 */
	public function testTheListenerSubscribesOnlyANewRequester(): void {
		$this->object('p-1', 'brpPerson', ['citizenServiceNumber' => '999993653']);
		$this->object('p-3', 'brpPerson', ['citizenServiceNumber' => '999990019']);
		$listener = new RequesterRegistrySubscriptionListener(
			new ObjectSchemaSlugResolver($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class)),
			$this->service(),
			$this->createMock(LoggerInterface::class),
		);

		$created = $this->caseWith(requester: 'p-1');
		$listener->handle(new ObjectCreatedEvent($created));
		$this->assertSame(['p-1'], $this->registry->requested);

		$listener->handle(new ObjectUpdatedEvent($this->caseWith(requester: 'p-1', title: 'Renamed'), $created));
		$this->assertSame(['p-1'], $this->registry->requested, 'Editing the title does not reach the BRP.');

		$listener->handle(new ObjectUpdatedEvent($this->caseWith(requester: 'p-3'), $created));
		$this->assertSame(['p-1', 'p-3'], $this->registry->requested);
	}//end testTheListenerSubscribesOnlyANewRequester()

	/**
	 * The listener never fails the save, and ignores objects that are not cases.
	 *
	 * @return void
	 */
	public function testTheListenerIgnoresOtherObjectsAndNeverThrows(): void {
		$subscriptions = $this->createMock(RequesterRegistrySubscription::class);
		$subscriptions->expects($this->once())->method('subscribe')->willThrowException(new RuntimeException('boom'));
		$listener = new RequesterRegistrySubscriptionListener(
			new ObjectSchemaSlugResolver($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class)),
			$subscriptions,
			$this->createMock(LoggerInterface::class),
		);

		$task = new ObjectEntity();
		$task->setObject(['requester' => 'p-1']);
		$task->setSchema('task');
		$listener->handle(new ObjectCreatedEvent($task));

		$listener->handle(new ObjectCreatedEvent($this->caseWith(requester: 'p-1')));
		$this->addToAssertionCount(1);
	}//end testTheListenerIgnoresOtherObjectsAndNeverThrows()

	/**
	 * A case naming a requester.
	 *
	 * @param string $requester The requester uuid.
	 * @param string $title The title.
	 *
	 * @return ObjectEntity The case.
	 */
	private function caseWith(string $requester, string $title = 'Kapvergunning'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => $title, 'requester' => $requester, 'initiatorType' => 'person']);
		$entity->setSchema('case');
		$entity->setUuid('case-1');

		return $entity;
	}//end caseWith()
}//end class
