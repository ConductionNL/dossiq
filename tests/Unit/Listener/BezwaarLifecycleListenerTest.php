<?php

/**
 * Dossiq BezwaarLifecycleListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarLifecycleListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The bezwaar lifecycle listener observes only the bezwaar schemas, and only
 * logs what it saw.
 *
 * @covers \OCA\Dossiq\Listener\BezwaarLifecycleListener
 * @uses \OCA\Dossiq\Service\ObjectSchemaSlugResolver
 */
class BezwaarLifecycleListenerTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh logger per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Each observed schema.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function observed(): array {
		return [
			'proceeding' => ['objectionProceeding'],
			'objection' => ['objection'],
			'hearing' => ['hearingSession'],
			'advice' => ['advisoryReport'],
			'decision' => ['decision'],
		];
	}//end observed()

	/**
	 * A created bezwaar object is logged with its id, case and status.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @dataProvider observed
	 *
	 * @return void
	 */
	public function testACreatedBezwaarObjectIsLogged(string $schema): void {
		$this->logger->expects($this->once())->method('debug')->with(
			'Dossiq bezwaar-lifecycle: observed ' . $schema . ' ' . ObjectCreatedEvent::class,
			[
				'app' => 'dossiq',
				'schema' => $schema,
				'objectId' => 'obj-1',
				'caseId' => 'case-1',
				'status' => 'open',
			]
		);

		$this->listener()->handle(new ObjectCreatedEvent($this->entity(schema: $schema)));
	}//end testACreatedBezwaarObjectIsLogged()

	/**
	 * An updated one is logged from the event's new object, with blanks for
	 * what it lacks.
	 *
	 * @return void
	 */
	public function testAnUpdatedBezwaarObjectIsLogged(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-2');
		$entity->setSchema('objection');
		$event = new ObjectUpdatedEvent($entity);
		$this->logger->expects($this->once())->method('debug')->with(
			'Dossiq bezwaar-lifecycle: observed objection ' . $event::class,
			['app' => 'dossiq', 'schema' => 'objection', 'objectId' => 'obj-2', 'caseId' => '', 'status' => '']
		);

		$this->listener()->handle($event);
	}//end testAnUpdatedBezwaarObjectIsLogged()

	/**
	 * Another schema, an unreadable object, or another event logs nothing.
	 *
	 * @return void
	 */
	public function testEverythingElseIsIgnored(): void {
		$this->logger->expects($this->never())->method('debug');
		$listener = $this->listener();

		$listener->handle(new ObjectCreatedEvent($this->entity(schema: 'case')));
		$listener->handle(new ObjectUpdatedEvent(new ObjectEntity()));
		$listener->handle(new Event());
	}//end testEverythingElseIsIgnored()

	/**
	 * An entity on a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setSchema($schema);
		$entity->setObject(['case' => 'case-1', 'status' => 'open']);

		return $entity;
	}//end entity()

	/**
	 * The listener under test.
	 *
	 * @return BezwaarLifecycleListener
	 */
	private function listener(): BezwaarLifecycleListener {
		return new BezwaarLifecycleListener(
			slugResolver: new ObjectSchemaSlugResolver(
				container: $this->createMock(originalClassName: ContainerInterface::class),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * Build a listener whose resolver maps schema ids to slugs the way
	 * OpenRegister's SchemaMapper would.
	 *
	 * @param array<string, string>          $slugsById Schema id => slug.
	 * @param LoggerInterface&MockObject     $logger    The listener's logger.
	 *
	 * @return BezwaarLifecycleListener The listener.
	 */
	private function resolvingListener(array $slugsById, LoggerInterface&MockObject $logger): BezwaarLifecycleListener {
		$schemaMapper = new class($slugsById) {
			/**
			 * Constructor.
			 *
			 * @param array<string, string> $slugsById Schema id => slug.
			 */
			public function __construct(private array $slugsById) {
			}//end __construct()

			/**
			 * Find a schema by id.
			 *
			 * @param string $id The schema id.
			 *
			 * @return object A schema exposing getSlug().
			 */
			public function find(string $id): object {
				if (array_key_exists($id, $this->slugsById) === false) {
					throw new \RuntimeException('Schema not found');
				}

				$slug = $this->slugsById[$id];

				return new class($slug) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(private string $slug) {
					}//end __construct()

					/**
					 * The schema slug.
					 *
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}//end getSlug()
				};
			}//end find()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($schemaMapper);

		$resolver = new ObjectSchemaSlugResolver(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		return new BezwaarLifecycleListener(slugResolver: $resolver, logger: $logger);
	}//end resolvingListener()

	/**
	 * An OpenRegister object carrying its schema as an ID, as the real one does.
	 *
	 * @param string               $schemaId The schema id on `@self.schema`.
	 * @param array<string, mixed> $object   The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function idEntity(string $schemaId, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($object['id'] ?? 'o1'));
		$entity->setSchema($schemaId);
		$entity->setObject($object);

		return $entity;
	}//end idEntity()

	/**
	 * A created objection whose payload carries only the schema ID runs the body.
	 *
	 * The positive control (#690). Before #690 this exact payload was rejected by
	 * the guard, silently, on every write.
	 *
	 * @return void
	 */
	public function testACreatedObjectionRunsTheHandlerBody(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with(
				$this->stringContains(string: 'observed objection'),
				$this->callback(callback: 
					static fn (array $context): bool => $context['schema'] === 'objection'
						&& $context['objectId'] === 'obj-1'
						&& $context['caseId'] === 'case-7'
				)
			);

		$this->resolvingListener(slugsById: ['116' => 'objection'], logger: $logger)->handle(
			new ObjectCreatedEvent($this->idEntity(schemaId: '116', object: ['id' => 'obj-1', 'case' => 'case-7']))
		);
	}//end testACreatedObjectionRunsTheHandlerBody()

	/**
	 * An updated hearing session runs the body too.
	 *
	 * @return void
	 */
	public function testAnUpdatedHearingSessionRunsTheHandlerBody(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with($this->stringContains(string: 'observed hearingSession'));

		$entity = $this->idEntity(schemaId: '42', object: ['id' => 'h-1']);

		$this->resolvingListener(slugsById: ['42' => 'hearingSession'], logger: $logger)->handle(
			new ObjectUpdatedEvent($entity, $entity)
		);
	}//end testAnUpdatedHearingSessionRunsTheHandlerBody()

	/**
	 * An object of an unrelated schema is left alone.
	 *
	 * @return void
	 */
	public function testAnUnrelatedSchemaIsLeftAlone(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('debug');

		$this->resolvingListener(slugsById: ['9' => 'case'], logger: $logger)->handle(
			new ObjectCreatedEvent($this->idEntity(schemaId: '9', object: ['id' => 'c-1']))
		);
	}//end testAnUnrelatedSchemaIsLeftAlone()

	/**
	 * A schema id that cannot be resolved is fail-closed.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsLeftAlone(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('debug');

		$this->resolvingListener(slugsById: [], logger: $logger)->handle(
			new ObjectCreatedEvent($this->idEntity(schemaId: '999', object: ['id' => 'x-1']))
		);
	}//end testAnUnresolvableSchemaIsLeftAlone()
}//end class
