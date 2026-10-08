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
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
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
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class VergunningaanvraagCreatedListenerTest extends TestCase {

	use MakesBackgroundServiceAccount;

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
			serviceAccount: $this->backgroundAccount(),
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
			->with($objectId, $this->callback(static fn (array $record): bool => ($record['id'] ?? null) === $objectId));

		$this->logger->expects($this->once())->method('info');

		$this->listener->handle(event: $event);
	}//end testHandleCallsDsoCaseServiceOnMatch()

	/**
	 * The mapped integriq verzoek, as the update event carries it.
	 *
	 * @param string $id     The uuid.
	 * @param string $status integriq's state.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function verzoek(string $id, string $status): \OCA\OpenRegister\Db\ObjectEntity {
		$entity = new \OCA\OpenRegister\Db\ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema('60');
		$entity->setObject(['status' => $status, 'mappedCaseTypes' => ['DSC-MILIEU'], 'mappedTitle' => 'Dsc']);
		return $entity;
	}//end verzoek()

	/**
	 * Point the listener at schema 60, integriq's dso_verzoek on the live rig.
	 *
	 * @return void
	 */
	private function configureVerzoekSchema(): void {
		$this->appConfig->method('getValueString')->willReturn('60');
	}//end configureVerzoekSchema()

	/**
	 * integriq writes the mapping in an update, and that write makes the case.
	 *
	 * Nobody is signed in (integriq's attachment job under cron), so the case
	 * is written as dossiq's background account and not refused as Anonymous.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function testTheMappingUpdateMakesTheCaseAsTheServiceAccount(): void {
		$this->configureVerzoekSchema();
		$writer = null;
		$this->dsoCaseService->expects($this->once())->method('createZaakFromVergunningaanvraag')
			->willReturnCallback(
				function (string $id, ?array $record) use (&$writer): array {
					$writer = $this->actingUid();
					$this->assertSame(['DSC-MILIEU'], $record['mappedCaseTypes'] ?? null);
					return ['id' => 'case-1'];
				}
			);

		$this->listener->handle(event: new ObjectUpdatedEvent($this->verzoek(id: 'verzoek-mapped-1', status: 'mapped')));

		$this->assertSame('dossiq-achtergrond', $writer);
		$this->assertNull($this->actingUid(), 'the previous (empty) session is restored');
	}//end testTheMappingUpdateMakesTheCaseAsTheServiceAccount()

	/**
	 * On a STAM push the account integriq's connection acts as stays the writer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function testASignedInIntakeAccountStaysTheWriter(): void {
		$this->configureVerzoekSchema();
		$this->acting = $this->backgroundUser(uid: 'dso-intake');
		$writer = null;
		$this->dsoCaseService->expects($this->once())->method('createZaakFromVergunningaanvraag')
			->willReturnCallback(
				function () use (&$writer): array {
					$writer = $this->actingUid();
					return ['id' => 'case-2'];
				}
			);

		$this->listener->handle(event: new ObjectUpdatedEvent($this->verzoek(id: 'verzoek-mapped-2', status: 'mapped')));

		$this->assertSame('dso-intake', $writer);
	}//end testASignedInIntakeAccountStaysTheWriter()

	/**
	 * The create integriq writes before mapping names no case type, so it waits.
	 *
	 * And it does not use up the per-request guard: the mapping update that
	 * follows in the same request still makes the case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function testTheReceivedCreateWaitsForTheMapping(): void {
		$this->configureVerzoekSchema();
		$this->dsoCaseService->expects($this->once())->method('createZaakFromVergunningaanvraag')->willReturn(['id' => 'case-3']);

		$this->listener->handle(event: new ObjectCreatedEvent($this->verzoek(id: 'verzoek-3', status: 'received')));
		$this->listener->handle(event: new ObjectUpdatedEvent($this->verzoek(id: 'verzoek-3', status: 'mapped')));
	}//end testTheReceivedCreateWaitsForTheMapping()

	/**
	 * Without a usable background account nothing is written and nothing throws.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function testWithoutAnAccountNothingIsWritten(): void {
		$this->configureVerzoekSchema();
		$this->configuredAccount = '';
		$this->dsoCaseService->expects($this->never())->method('createZaakFromVergunningaanvraag');

		$this->listener->handle(event: new ObjectUpdatedEvent($this->verzoek(id: 'verzoek-4', status: 'mapped')));

		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
	}//end testWithoutAnAccountNothingIsWritten()
}//end class
