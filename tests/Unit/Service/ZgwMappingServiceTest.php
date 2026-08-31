<?php

/**
 * ZgwMappingService Unit Tests
 *
 * Tests for the ZGW mapping configuration service.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ZgwMappingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ZgwMappingService class.
 *
 * @covers \OCA\Dossiq\Service\ZgwMappingService
 */
class ZgwMappingServiceTest extends TestCase {

	/**
	 * The mocked app configuration service.
	 *
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The mocked logger interface.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var ZgwMappingService
	 */
	private ZgwMappingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ZgwMappingService(
			$this->appConfig,
			$this->logger,
		);

	}//end setUp()

	/**
	 * Test that getMapping returns null when no mapping is stored.
	 *
	 * @return void
	 */
	public function testGetMappingReturnsNullWhenEmpty(): void {
		$this->appConfig
			->expects($this->once())
			->method('getValueString')
			->with('dossiq', 'zgw_mapping_zaak', '')
			->willReturn('');

		$this->assertNull($this->service->getMapping('zaak'));

	}//end testGetMappingReturnsNullWhenEmpty()

	/**
	 * Test that getMapping returns decoded config when stored.
	 *
	 * @return void
	 */
	public function testGetMappingReturnsDecodedConfig(): void {
		$config = ['field_mappings' => ['title' => 'omschrijving']];

		$this->appConfig
			->expects($this->once())
			->method('getValueString')
			->with('dossiq', 'zgw_mapping_zaak', '')
			->willReturn(json_encode($config));

		$result = $this->service->getMapping('zaak');

		$this->assertSame($config, $result);

	}//end testGetMappingReturnsDecodedConfig()

	/**
	 * Test that getMapping returns null for invalid JSON.
	 *
	 * @return void
	 */
	public function testGetMappingReturnsNullForInvalidJson(): void {
		$this->appConfig
			->expects($this->once())
			->method('getValueString')
			->with('dossiq', 'zgw_mapping_zaak', '')
			->willReturn('not-valid-json');

		$this->assertNull($this->service->getMapping('zaak'));

	}//end testGetMappingReturnsNullForInvalidJson()

	/**
	 * Test that saveMapping persists JSON to IAppConfig.
	 *
	 * @return void
	 */
	public function testSaveMappingPersistsJson(): void {
		$config = ['field_mappings' => ['title' => 'omschrijving']];

		$this->appConfig
			->expects($this->once())
			->method('setValueString')
			->with(
				'dossiq',
				'zgw_mapping_zaaktype',
				json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			);

		$this->logger
			->expects($this->once())
			->method('info');

		$this->service->saveMapping('zaaktype', $config);

	}//end testSaveMappingPersistsJson()

	/**
	 * Test that deleteMapping removes the config key.
	 *
	 * @return void
	 */
	public function testDeleteMappingRemovesKey(): void {
		$this->appConfig
			->expects($this->once())
			->method('deleteKey')
			->with('dossiq', 'zgw_mapping_status');

		$this->service->deleteMapping('status');

	}//end testDeleteMappingRemovesKey()

	/**
	 * Test that hasMapping returns true when mapping exists.
	 *
	 * @return void
	 */
	public function testHasMappingReturnsTrueWhenExists(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('{"foo":"bar"}');

		$this->assertTrue($this->service->hasMapping('zaak'));

	}//end testHasMappingReturnsTrueWhenExists()

	/**
	 * Test that hasMapping returns false when mapping does not exist.
	 *
	 * @return void
	 */
	public function testHasMappingReturnsFalseWhenMissing(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->assertFalse($this->service->hasMapping('zaak'));

	}//end testHasMappingReturnsFalseWhenMissing()

	/**
	 * Test that getResourceKeys returns known keys.
	 *
	 * @return void
	 */
	public function testGetResourceKeysReturnsKnownKeys(): void {
		$keys = $this->service->getResourceKeys();

		$this->assertContains('zaak', $keys);
		$this->assertContains('zaaktype', $keys);
		$this->assertContains('status', $keys);
		$this->assertContains('besluit', $keys);
		$this->assertContains('enkelvoudiginformatieobject', $keys);
		$this->assertCount(26, $keys);

	}//end testGetResourceKeysReturnsKnownKeys()

	/**
	 * Test that listMappings returns all keys with values.
	 *
	 * @return void
	 */
	public function testListMappingsReturnsAllKeys(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default): string {
					if ($key === 'zgw_mapping_zaak') {
						return '{"title":"omschrijving"}';
					}

					return '';
				}
			);

		$mappings = $this->service->listMappings();

		$this->assertArrayHasKey('zaak', $mappings);
		$this->assertArrayHasKey('zaaktype', $mappings);
		$this->assertSame(['title' => 'omschrijving'], $mappings['zaak']);
		// `caseType` is the English alias for `zaaktype`, and is deliberately
		// NOT one of RESOURCE_KEYS. Asserting it is null read as a contract but
		// was reading an absent key, so it passed on a PHP warning rather than
		// on the service agreeing with it.
		$this->assertArrayNotHasKey('caseType', $mappings);

	}//end testListMappingsReturnsAllKeys()

	/**
	 * Test that resetToDefault saves default configuration.
	 *
	 * @return void
	 */
	public function testResetToDefaultSavesDefault(): void {
		$defaults = [
			'zaak' => ['field_mappings' => ['title' => 'omschrijving']],
		];

		$this->appConfig
			->expects($this->once())
			->method('setValueString');

		$this->service->resetToDefault('zaak', $defaults);

	}//end testResetToDefaultSavesDefault()

	/**
	 * Test that resetToDefault does nothing for unknown key.
	 *
	 * @return void
	 */
	public function testResetToDefaultIgnoresUnknownKey(): void {
		$this->appConfig
			->expects($this->never())
			->method('setValueString');

		$this->service->resetToDefault('unknown_key', []);

	}//end testResetToDefaultIgnoresUnknownKey()

}//end class
