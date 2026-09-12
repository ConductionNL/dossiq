<?php

/**
 * IntegrationStatusService unit tests.
 *
 * The service exists so the Integrations page can say something the app can
 * back, and every test here guards one way it could quietly stop doing that:
 * writing a status nothing renders, writing against a key no row has, blanking
 * the row's title by sending a partial object, turning a working connection
 * test into a 500 because the page could not be reached, or restating a card
 * the save never touched.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The slice of OpenRegister's ObjectService this service uses.
 *
 * Declared with the signatures OpenRegister really has, not the ones the
 * service happens to call: a fake that agrees with its caller cannot fail.
 */
interface IntegrationObjectServiceStub {

	/**
	 * Search objects by register/schema slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array $filters The query filters.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array;

	/**
	 * Search objects by numeric @self query.
	 *
	 * @param array $query The query payload.
	 *
	 * @return array
	 */
	public function searchObjects(array $query): array;

	/**
	 * Save or update an object.
	 *
	 * @param array $object The object payload.
	 * @param int|string $register The register.
	 * @param int|string $schema The schema.
	 * @param string|null $uuid The object id on an update.
	 *
	 * @return array
	 */
	public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): array;
}//end interface

/**
 * Unit tests for IntegrationStatusService.
 *
 * @covers \OCA\Dossiq\Service\IntegrationStatusService
 */
class IntegrationStatusServiceTest extends TestCase {

	/**
	 * Mocked SettingsService.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Mocked app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The row the stubbed store answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $row = [];

	/**
	 * What saveObject() was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->row = [
			'id' => 'row-mailbox',
			'@self' => ['id' => 'row-mailbox'],
			'key' => 'mailbox',
			'title' => 'Shared mailbox',
			'status' => 'unconfigured',
			'statusMessage' => 'Not checked yet',
			'order' => 50,
		];
		$this->saved = [];
	}//end setUp()

	/**
	 * Build the service with a stubbed object store.
	 *
	 * @param bool $withStore Whether OpenRegister answers at all.
	 * @param array<int, array<string, mixed>> $rows The rows the search returns.
	 *
	 * @return IntegrationStatusService
	 */
	private function service(bool $withStore = true, ?array $rows = null): IntegrationStatusService {
		if ($withStore === false) {
			$this->settings->method('getObjectService')->willReturn(null);
		} else {
			$store = $this->createMock(IntegrationObjectServiceStub::class);
			$store->method('searchObjectsBySlug')->willReturn($rows ?? [$this->row]);
			$store->method('searchObjects')->willReturn($rows ?? [$this->row]);
			$store->method('saveObject')->willReturnCallback(
				function (array $object, int|string $register, int|string $schema, ?string $uuid = null): array {
					$this->saved[] = ['object' => $object, 'uuid' => $uuid];
					return $object;
				}
			);
			$this->settings->method('getObjectService')->willReturn($store);
		}

		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'dossiq_integration_schema' => 'dossiqIntegration',
				default => $default,
			}
		);

		return new IntegrationStatusService(
			settingsService: $this->settings,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A known key writes the three fields the card reads.
	 *
	 * @return void
	 */
	public function testKnownKeyUpdatesStatusMessageAndCheckedAt(): void {
		$service = $this->service();

		$this->assertTrue($service->record(key: 'mailbox', status: 'error', message: 'Connection refused'));
		$this->assertCount(1, $this->saved);

		$written = $this->saved[0]['object'];
		$this->assertSame('error', $written['status']);
		$this->assertSame('Connection refused', $written['statusMessage']);
		$this->assertNotEmpty($written['checkedAt']);
		$this->assertSame('row-mailbox', $this->saved[0]['uuid']);
	}//end testKnownKeyUpdatesStatusMessageAndCheckedAt()

	/**
	 * The write is PUT-semantic, so the whole row goes back.
	 *
	 * Sending only the three changed fields would blank the title the page
	 * renders, and the page would show a status against no connection.
	 *
	 * @return void
	 */
	public function testWriteCarriesTheWholeRowNotJustTheChangedFields(): void {
		$service = $this->service();
		$service->record(key: 'mailbox', status: 'configured', message: 'ok');

		$written = $this->saved[0]['object'];
		$this->assertSame('Shared mailbox', $written['title']);
		$this->assertSame('mailbox', $written['key']);
		$this->assertSame(50, $written['order']);
		$this->assertArrayNotHasKey('@self', $written);
	}//end testWriteCarriesTheWholeRowNotJustTheChangedFields()

	/**
	 * An unknown key logs and returns false rather than throwing.
	 *
	 * @return void
	 */
	public function testUnknownKeyDoesNotThrow(): void {
		$service = $this->service();

		$this->assertFalse($service->record(key: 'sharepoint', status: 'configured'));
		$this->assertSame([], $this->saved);
	}//end testUnknownKeyDoesNotThrow()

	/**
	 * A status outside the four is refused, not written.
	 *
	 * @return void
	 */
	public function testStatusMustBeOneOfTheFour(): void {
		$service = $this->service();

		$this->assertFalse($service->record(key: 'mailbox', status: 'degraded'));
		$this->assertSame([], $this->saved);

		foreach (IntegrationStatusService::STATUSES as $status) {
			$this->saved = [];
			$this->assertTrue($service->record(key: 'mailbox', status: $status));
		}
	}//end testStatusMustBeOneOfTheFour()

	/**
	 * A key with no seeded row is reported, not invented.
	 *
	 * @return void
	 */
	public function testMissingRowIsReportedRatherThanCreated(): void {
		$service = $this->service(withStore: true, rows: []);

		$this->assertFalse($service->record(key: 'mailbox', status: 'configured'));
		$this->assertSame([], $this->saved);
	}//end testMissingRowIsReportedRatherThanCreated()

	/**
	 * Without OpenRegister the recorder is a no-op, never an exception.
	 *
	 * It runs beside a connection test whose own answer is what the admin
	 * asked for; a page that cannot be updated must not turn a successful
	 * probe into a 500.
	 *
	 * @return void
	 */
	public function testNoObjectServiceIsANoOp(): void {
		$service = $this->service(withStore: false);

		$this->assertFalse($service->record(key: 'mailbox', status: 'configured'));
	}//end testNoObjectServiceIsANoOp()

	/**
	 * A save whose required keys are all filled marks the section configured.
	 *
	 * @return void
	 */
	public function testFilledSectionSavesConfigured(): void {
		$this->row = ['id' => 'row-kcc', 'key' => 'kcc', 'title' => 'KCC desk', 'status' => 'unconfigured'];
		$this->appConfig->method('getValueString')->willReturn('both');
		$service = $this->service();

		$written = $service->recordFromSave(saved: ['identification_method' => 'both']);

		$this->assertSame(['kcc' => 'configured'], $written);
		$this->assertSame('configured', $this->saved[0]['object']['status']);
	}//end testFilledSectionSavesConfigured()

	/**
	 * A save that cleared the required keys marks the section not configured.
	 *
	 * @return void
	 */
	public function testClearedSectionSavesUnconfigured(): void {
		$this->row = ['id' => 'row-kcc', 'key' => 'kcc', 'title' => 'KCC desk', 'status' => 'configured'];
		$this->appConfig->method('getValueString')->willReturn('');
		$service = $this->service();

		$written = $service->recordFromSave(saved: ['identification_method' => '']);

		$this->assertSame(['kcc' => 'unconfigured'], $written);
		$this->assertSame('unconfigured', $this->saved[0]['object']['status']);
		$this->assertSame('Not checked yet', $this->saved[0]['object']['statusMessage']);
	}//end testClearedSectionSavesUnconfigured()

	/**
	 * A save that named no connection's keys writes nothing.
	 *
	 * Saving the KCC form must not restate what the ZGW card says: a card that
	 * moves when nothing about its connection changed is a card nobody trusts.
	 *
	 * @return void
	 */
	public function testASaveOnlyTouchesTheSectionsItNamed(): void {
		$this->appConfig->method('getValueString')->willReturn('filled');
		$service = $this->service();

		$this->assertSame([], $service->recordFromSave(saved: ['advice_reminder_days' => '7']));
		$this->assertSame([], $this->saved);
	}//end testASaveOnlyTouchesTheSectionsItNamed()

	/**
	 * Every key the save map names is a key the seed ships.
	 *
	 * A required-keys entry for a connection with no row would fail on every
	 * save, quietly, in a warning nobody reads.
	 *
	 * @return void
	 */
	public function testEverySaveMappedKeyIsASeededConnection(): void {
		foreach (array_keys(IntegrationStatusService::SAVE_REQUIRED_KEYS) as $key) {
			$this->assertContains($key, IntegrationStatusService::KEYS);
		}
	}//end testEverySaveMappedKeyIsASeededConnection()

	/**
	 * The probed connections are deliberately absent from the save map.
	 *
	 * A saved form must not overwrite what the network answered.
	 *
	 * @return void
	 */
	public function testProbedConnectionsAreNotDrivenBySaves(): void {
		$this->assertArrayNotHasKey('stuf', IntegrationStatusService::SAVE_REQUIRED_KEYS);
		$this->assertArrayNotHasKey('mailbox', IntegrationStatusService::SAVE_REQUIRED_KEYS);
	}//end testProbedConnectionsAreNotDrivenBySaves()
}//end class
