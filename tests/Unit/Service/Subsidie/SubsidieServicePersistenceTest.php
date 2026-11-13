<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What SubsidieService stores, refuses and asks the register for.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Intake, status transitions and listing of subsidy applications.
 *
 * @covers \OCA\Dossiq\Service\Subsidie\SubsidieService
 * @uses   \OCA\Dossiq\Service\Subsidie\SubsidieSchemaLookup
 * @uses   \OCA\Dossiq\Service\Subsidie\CofinancieringValidator
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class SubsidieServicePersistenceTest extends TestCase {

	private InMemoryRegister $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * A settings double answering the given object service and config.
	 *
	 * @param object|null           $objectService The register.
	 * @param array<string, string> $config        Config overrides.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config = []): SettingsService {
		$map = array_merge(['register' => 'dossiq', 'subsidie_aanvraag_schema' => 'subsidieAanvraag'], $config);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($map[$key] ?? $default)
		);
		return $settings;
	}//end settings()

	/**
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return SubsidieService
	 */
	private function service(?LoggerInterface $logger = null): SubsidieService {
		return new SubsidieService(settingsService: $this->settings(objectService: $this->store), logger: ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * A new application is received, gets a 13-week decision term, a masked BSN, and caller identity keys are dropped.
	 *
	 * @return void
	 */
	public function testCreateAanvraagStoresAReceivedApplication(): void {
		$stored = $this->service()->createAanvraag(payload: [
			'id' => 'forged',
			'@self' => ['owner' => 'x'],
			'subsidyScheme' => 'regeling-1',
			'applicantBsnRef' => '123456789',
		]);

		$this->assertSame('received', $stored['status']);
		$this->assertSame('******789', $stored['applicantBsnRef']);
		$this->assertSame((new \DateTimeImmutable())->modify('+91 days')->format('Y-m-d'), $stored['beslistermijn']);
		$this->assertSame('generated-1', $stored['id']);
		$this->assertArrayNotHasKey('@self', $this->store->row(schema: 'subsidieAanvraag', uuid: 'generated-1'));
		$this->assertSame('******789', $this->store->row(schema: 'subsidieAanvraag', uuid: 'generated-1')['applicantBsnRef']);
	}//end testCreateAanvraagStoresAReceivedApplication()

	/**
	 * A custom term and a timer service that rolls the end date are honoured.
	 *
	 * @return void
	 */
	public function testCreateAanvraagUsesTheTimerToRollTheTerm(): void {
		$timer = $this->createMock(TermijnTimerService::class);
		$timer->expects($this->once())->method('rollTermEndFor')
			->willReturn(new \DateTimeImmutable('2030-01-02'));
		$service = new SubsidieService(settingsService: $this->settings(objectService: $this->store), logger: new NullLogger(), timerService: $timer);

		$stored = $service->createAanvraag(payload: ['subsidyScheme' => 'r'], termWeken: 2);
		$this->assertSame('2030-01-02', $stored['beslistermijn']);
	}//end testCreateAanvraagUsesTheTimerToRollTheTerm()

	/**
	 * A missing scheme is refused before anything is stored.
	 *
	 * @return void
	 */
	public function testCreateAanvraagRequiresAScheme(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('subsidieregeling is verplicht');
		try {
			$this->service()->createAanvraag(payload: ['subsidyScheme' => '']);
		} finally {
			$this->assertSame(0, $this->store->writes);
		}
	}//end testCreateAanvraagRequiresAScheme()

	/**
	 * A failing save is logged and becomes a bad request.
	 *
	 * @return void
	 */
	public function testCreateAanvraagTurnsAFailedSaveIntoABadRequest(): void {
		$this->store->refusedSchemas = ['subsidieAanvraag'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Dossiq subsidie: createAanvraag failed: Save refused for subsidieAanvraag');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Kon subsidieaanvraag niet aanmaken');
		$this->service(logger: $logger)->createAanvraag(payload: ['subsidyScheme' => 'r']);
	}//end testCreateAanvraagTurnsAFailedSaveIntoABadRequest()

	/**
	 * Without OpenRegister, or without a configured schema, intake refuses.
	 *
	 * @return void
	 */
	public function testCreateAanvraagRefusesWithoutARegister(): void {
		$service = new SubsidieService(settingsService: $this->settings(objectService: null), logger: new NullLogger());
		try {
			$service->createAanvraag(payload: ['subsidyScheme' => 'r']);
			$this->fail('no register must refuse');
		} catch (OCSBadRequestException $e) {
			$this->assertSame('OpenRegister is niet beschikbaar', $e->getMessage());
		}

		$service = new SubsidieService(settingsService: $this->settings(objectService: $this->store, config: ['subsidie_aanvraag_schema' => '']), logger: new NullLogger());
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Subsidie-schema is niet geconfigureerd');
		$service->createAanvraag(payload: ['subsidyScheme' => 'r']);
	}//end testCreateAanvraagRefusesWithoutARegister()

	/**
	 * An allowed transition is written as a partial update keeping the other fields.
	 *
	 * @return void
	 */
	public function testTransitionAanvraagWritesTheNewStatus(): void {
		$this->store->seed(schema: 'subsidieAanvraag', uuid: 'a-1', row: ['status' => 'received', 'subsidyScheme' => 'r']);

		$result = $this->service()->transitionAanvraag(id: 'a-1', toStatus: 'in_assessment');

		$this->assertSame('in_assessment', $result['status']);
		$this->assertSame(['status' => 'in_assessment', 'subsidyScheme' => 'r', 'id' => 'a-1'], $this->store->row(schema: 'subsidieAanvraag', uuid: 'a-1'));
	}//end testTransitionAanvraagWritesTheNewStatus()

	/**
	 * Unknown statuses, missing applications and forbidden transitions are refused unwritten.
	 *
	 * @return void
	 */
	public function testTransitionAanvraagRefusals(): void {
		$this->store->seed(schema: 'subsidieAanvraag', uuid: 'a-1', row: ['status' => 'granted']);
		$cases = [
			['a-1', 'onzin', 'Onbekende status: onzin'],
			['nope', 'withdrawn', 'Subsidieaanvraag niet gevonden'],
			['a-1', 'in_assessment', 'Statusovergang granted -> in_assessment is niet toegestaan'],
		];
		foreach ($cases as [$id, $to, $message]) {
			try {
				$this->service()->transitionAanvraag(id: $id, toStatus: $to);
				$this->fail('should refuse '.$to);
			} catch (OCSBadRequestException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame(0, $this->store->writes);
	}//end testTransitionAanvraagRefusals()

	/**
	 * A stored application without a status starts from received.
	 *
	 * @return void
	 */
	public function testTransitionAanvraagDefaultsAMissingStatusToReceived(): void {
		$this->store->seed(schema: 'subsidieAanvraag', uuid: 'a-2', row: []);
		$this->assertSame('withdrawn', $this->service()->transitionAanvraag(id: 'a-2', toStatus: 'withdrawn')['status']);

		$this->store->seed(schema: 'subsidieAanvraag', uuid: 'a-3', row: []);
		$this->expectExceptionMessage('Statusovergang received -> granted is niet toegestaan');
		$this->service()->transitionAanvraag(id: 'a-3', toStatus: 'granted');
	}//end testTransitionAanvraagDefaultsAMissingStatusToReceived()

	/**
	 * A failing write is logged and becomes a bad request.
	 *
	 * @return void
	 */
	public function testTransitionAanvraagTurnsAFailedWriteIntoABadRequest(): void {
		$this->store->seed(schema: 'subsidieAanvraag', uuid: 'a-1', row: ['status' => 'received']);
		$this->store->refusedSchemas = ['subsidieAanvraag'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringStartsWith('Dossiq subsidie: transitionAanvraag failed: '));

		$this->expectExceptionMessage('Kon status niet bijwerken');
		$this->service(logger: $logger)->transitionAanvraag(id: 'a-1', toStatus: 'in_assessment');
	}//end testTransitionAanvraagTurnsAFailedWriteIntoABadRequest()

	/**
	 * Only the known, non-empty filters reach the register query.
	 *
	 * @return void
	 */
	public function testListAanvragenPassesOnlyKnownFilters(): void {
		$register = new class {
			public array $queries = [];

			public function findAll(array $config): array {
				$this->queries[] = $config;
				return [['id' => 'a-1']];
			}
		};
		$service = new SubsidieService(settingsService: $this->settings(objectService: $register, config: ['register' => '7', 'subsidie_aanvraag_schema' => '9']), logger: new NullLogger());

		$result = $service->listAanvragen(filters: ['status' => 'granted', 'subsidyScheme' => '', 'handler' => 'bob', 'evil' => 'x']);

		$this->assertSame([['id' => 'a-1']], $result);
		$this->assertSame([['filters' => ['register' => 7, 'schema' => 9, 'status' => 'granted', 'handler' => 'bob']]], $register->queries);
	}//end testListAanvragenPassesOnlyKnownFilters()
}//end class
