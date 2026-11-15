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
		$this->logger = $this->createMock(LoggerInterface::class);
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
	 * An updated one is logged under the update event, with blanks for what
	 * it lacks.
	 *
	 * @return void
	 */
	public function testAnUpdatedBezwaarObjectIsLogged(): void {
		$event = $this->updated(['id' => 'obj-2', '@self' => ['schema' => 'objection']]);
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
		$listener->handle($this->updated('not an object'));
		$listener->handle($this->updated(new \stdClass()));
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
	 * An update event whose getObject() returns the value.
	 *
	 * @param mixed $object The value.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(mixed $object): ObjectUpdatedEvent {
		return new class ($object) extends ObjectUpdatedEvent {
			/**
			 * Constructor.
			 *
			 * @param mixed $payload The value.
			 */
			public function __construct(private readonly mixed $payload) {
				parent::__construct(new ObjectEntity());
			}

			/**
			 * The value.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->payload;
			}
		};
	}//end updated()

	/**
	 * The listener under test.
	 *
	 * @return BezwaarLifecycleListener
	 */
	private function listener(): BezwaarLifecycleListener {
		return new BezwaarLifecycleListener(
			slugResolver: new ObjectSchemaSlugResolver(
				container: $this->createMock(ContainerInterface::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			logger: $this->logger,
		);
	}//end listener()
}//end class
