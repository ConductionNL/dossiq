<?php

/**
 * VergunningaanvraagCreatedListener Unit Tests
 *
 * Tests for the listener that triggers DSO zaak creation when a
 * vergunningaanvraag object is created via OpenRegister. Covers schema
 * matching, non-matching schema pass-through, and wrong event type handling.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\VergunningaanvraagCreatedListener;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VergunningaanvraagCreatedListener.
 *
 * TWO `class_exists(ObjectCreatedEvent::class)` SKIP GUARDS WERE REMOVED HERE,
 * and please do not put them back. `tests/bootstrap.php` includes
 * `tests/Stubs/Event/ObjectCreatedEventStub.php` whenever OpenRegister's real
 * class is absent, so the name resolves in every process that runs this file:
 * the real class when OpenRegister is installed, the stub when it is not. The
 * guards could therefore never fire, and a skip that cannot fire reads exactly
 * like a test that passed.
 *
 * @covers \OCA\Dossiq\Listener\VergunningaanvraagCreatedListener
 */
class VergunningaanvraagCreatedListenerTest extends TestCase {

	/**
	 * The IAppConfig mock.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The DsoCaseService mock.
	 *
	 * @var DsoCaseService|MockObject
	 */
	private DsoCaseService $dsoCaseService;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var VergunningaanvraagCreatedListener
	 */
	private VergunningaanvraagCreatedListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->dsoCaseService = $this->createMock(DsoCaseService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new VergunningaanvraagCreatedListener(
			appConfig: $this->appConfig,
			dsoCaseService: $this->dsoCaseService,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that handle() ignores events that are not ObjectCreatedEvent.
	 *
	 * DsoCaseService must NOT be called when the event is a different type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
	 */
	public function testHandleIgnoresNonObjectCreatedEvents(): void {
		$this->dsoCaseService->expects($this->never())->method('createZaakFromVergunningaanvraag');

		$otherEvent = new Event();
		$this->listener->handle(event: $otherEvent);
	}//end testHandleIgnoresNonObjectCreatedEvents()

	/**
	 * Test that handle() ignores ObjectCreatedEvent with non-matching schema.
	 *
	 * When the object's schema id does not match the configured vergunningaanvraag
	 * schema, DsoCaseService must NOT be called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
	 */
	public function testHandleIgnoresNonMatchingSchema(): void {
		// ObjectCreatedEvent::getObject() is type-hinted to return an
		// ObjectEntity, so build a real entity (its jsonSerialize() exposes the
		// schema under @self.schema and the id at top level) and wrap it in a
		// real event.
		$entity = new \OCA\OpenRegister\Db\ObjectEntity();
		$entity->setUuid('object-uuid-1');
		$entity->setSchema('some-other-schema-id');
		$event = new ObjectCreatedEvent($entity);

		$this->appConfig
			->method('getValueString')
			->willReturn('configured-vergunningaanvraag-schema-id');

		$this->dsoCaseService->expects($this->never())->method('createZaakFromVergunningaanvraag');

		$this->listener->handle(event: $event);
	}//end testHandleIgnoresNonMatchingSchema()

	/**
	 * Test that handle() calls DsoCaseService when the schema matches.
	 *
	 * When the object's schema id matches the configured vergunningaanvraag
	 * schema, createZaakFromVergunningaanvraag() must be called once with the
	 * correct object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
	 */
	public function testHandleCallsDsoCaseServiceOnMatch(): void {
		$configuredSchemaId = 'vergunning-schema-123';
		$objectId = 'aanvraag-object-uuid';

		$entity = new \OCA\OpenRegister\Db\ObjectEntity();
		$entity->setUuid($objectId);
		$entity->setSchema($configuredSchemaId);
		$event = new ObjectCreatedEvent($entity);

		$this->appConfig
			->method('getValueString')
			->with(
				$this->equalTo('dossiq'),
				$this->equalTo('dso_vergunningaanvraag_schema'),
				$this->anything()
			)
			->willReturn($configuredSchemaId);

		$this->dsoCaseService
			->expects($this->once())
			->method('createZaakFromVergunningaanvraag')
			->with(permitApplicationId: $objectId);

		$this->logger->expects($this->once())->method('info');

		$this->listener->handle(event: $event);
	}//end testHandleCallsDsoCaseServiceOnMatch()
}//end class
