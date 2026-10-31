<?php

/**
 * BezwaarLifecycleListener unit tests.
 *
 * The positive control #690 asked for. The listener's guard used to read
 * `@self.schema` as if it were a slug; OpenRegister puts the schema ID there,
 * so the strict `in_array()` never matched and the body never ran. A test that
 * only asserts an early return for an unrelated schema passes against a
 * listener that does nothing, so these tests drive a real
 * ObjectSchemaSlugResolver with an id-carrying payload and assert the body
 * actually executes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarLifecycleListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The lifecycle observer runs for bezwaar writes, and only for those.
 *
 * @covers \OCA\Dossiq\Listener\BezwaarLifecycleListener
 */
class BezwaarLifecycleListenerTest extends TestCase {

	/**
	 * Build a listener whose resolver maps schema ids to slugs the way
	 * OpenRegister's SchemaMapper would.
	 *
	 * @param array<string, string>          $slugsById Schema id => slug.
	 * @param LoggerInterface&MockObject     $logger    The listener's logger.
	 *
	 * @return BezwaarLifecycleListener The listener.
	 */
	private function listener(array $slugsById, LoggerInterface&MockObject $logger): BezwaarLifecycleListener {
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
	}//end listener()

	/**
	 * An OpenRegister object carrying its schema as an ID, as the real one does.
	 *
	 * @param string               $schemaId The schema id on `@self.schema`.
	 * @param array<string, mixed> $object   The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schemaId, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($object['id'] ?? 'o1'));
		$entity->setSchema($schemaId);
		$entity->setObject($object);

		return $entity;
	}//end entity()

	/**
	 * A created objection whose payload carries only the schema ID runs the body.
	 *
	 * 🔴 THE POSITIVE CONTROL. Before #690 this exact payload was rejected by
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

		$this->listener(slugsById: ['116' => 'objection'], logger: $logger)->handle(
			new ObjectCreatedEvent($this->entity(schemaId: '116', object: ['id' => 'obj-1', 'case' => 'case-7']))
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

		$entity = $this->entity(schemaId: '42', object: ['id' => 'h-1']);

		$this->listener(slugsById: ['42' => 'hearingSession'], logger: $logger)->handle(
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

		$this->listener(slugsById: ['9' => 'case'], logger: $logger)->handle(
			new ObjectCreatedEvent($this->entity(schemaId: '9', object: ['id' => 'c-1']))
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

		$this->listener(slugsById: [], logger: $logger)->handle(
			new ObjectCreatedEvent($this->entity(schemaId: '999', object: ['id' => 'x-1']))
		);
	}//end testAnUnresolvableSchemaIsLeftAlone()
}//end class
