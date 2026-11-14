<?php

/**
 * ConfiguredRegistryService edge-case Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredRegistryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The edges of the configured registry: a failing listing degrades and is
 * logged, an unreachable OpenRegister reads as unconfigured, whatever shape
 * the store hands back becomes an array, and the store status says whether
 * a registry URL is set.
 */
#[CoversClass(ConfiguredRegistryService::class)]
class ConfiguredRegistryServiceEdgesTest extends TestCase {

	/**
	 * Settings service, mocked.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Logger, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Integration status, mocked.
	 *
	 * @var IntegrationStatusService&MockObject
	 */
	private IntegrationStatusService $integrationStatus;

	/**
	 * Set up mocks with register "dossiq" and schema "role".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'role')
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->integrationStatus = $this->createMock(IntegrationStatusService::class);
	}//end setUp()

	/**
	 * The subject.
	 *
	 * @return ConfiguredRegistryService
	 */
	private function subject(): ConfiguredRegistryService {
		return new ConfiguredRegistryService(
			settingsService: $this->settingsService,
			logger: $this->logger,
			integrationStatus: $this->integrationStatus,
		);
	}//end subject()

	/**
	 * An object service whose save returns the given value.
	 *
	 * @param mixed $saved What saveObject answers.
	 *
	 * @return object
	 */
	private function savingAs(mixed $saved): object {
		return new class($saved) {
			/**
			 * @param mixed $saved What saveObject answers.
			 */
			public function __construct(private mixed $saved) {
			}

			/**
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param array<string, mixed> $object   Payload.
			 *
			 * @return mixed
			 */
			public function saveObject(string $register, string $schema, array $object): mixed {
				return $this->saved;
			}
		};
	}//end savingAs()

	/**
	 * A listing that throws degrades to an empty list and logs a warning.
	 *
	 * @return void
	 */
	public function testAFailingListingDegradesAndIsLogged(): void {
		$objects = new class {
			/**
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				throw new \RuntimeException('register offline');
			}
		};
		$this->settingsService->method('getObjectService')->willReturn($objects);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Configured registry listing failed', ['key' => 'role_schema', 'error' => 'register offline']);

		$this->assertSame([], $this->subject()->list(schemaConfigKey: 'role_schema'));
	}//end testAFailingListingDegradesAndIsLogged()

	/**
	 * An unreachable OpenRegister reads as unconfigured: list is empty, save refuses.
	 *
	 * @return void
	 */
	public function testAnUnreachableObjectServiceReadsAsUnconfigured(): void {
		$this->settingsService->method('getObjectService')->willThrowException(new \RuntimeException('container error'));

		$this->assertSame([], $this->subject()->list(schemaConfigKey: 'role_schema'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Not configured: no register or schema for role_schema');
		$this->subject()->save(schemaConfigKey: 'role_schema', data: ['name' => 'x']);
	}//end testAnUnreachableObjectServiceReadsAsUnconfigured()

	/**
	 * A saved entity is read through jsonSerialize().
	 *
	 * @return void
	 */
	public function testASavedEntityIsSerialised(): void {
		$entity = new class {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'u-1', 'name' => 'Clerk'];
			}
		};
		$this->settingsService->method('getObjectService')->willReturn($this->savingAs($entity));

		$this->assertSame(['id' => 'u-1', 'name' => 'Clerk'], $this->subject()->save(schemaConfigKey: 'role_schema', data: ['name' => 'Clerk']));
	}//end testASavedEntityIsSerialised()

	/**
	 * A plain saved object yields its public properties.
	 *
	 * @return void
	 */
	public function testAPlainSavedObjectYieldsItsProperties(): void {
		$plain = new \stdClass();
		$plain->id = 'u-2';
		$plain->name = 'Reviewer';
		$this->settingsService->method('getObjectService')->willReturn($this->savingAs($plain));

		$this->assertSame(['id' => 'u-2', 'name' => 'Reviewer'], $this->subject()->save(schemaConfigKey: 'role_schema', data: ['name' => 'Reviewer']));
	}//end testAPlainSavedObjectYieldsItsProperties()

	/**
	 * A save that answers neither array nor object returns the sent payload, with the id given.
	 *
	 * @return void
	 */
	public function testAnUnusableSaveAnswerFallsBackToThePayload(): void {
		$this->settingsService->method('getObjectService')->willReturn($this->savingAs(true));

		$this->assertSame(
			['name' => 'Archivist', 'id' => 'u-3'],
			$this->subject()->save(schemaConfigKey: 'role_schema', data: ['name' => 'Archivist', '@self' => ['id' => 'other']], id: 'u-3')
		);
	}//end testAnUnusableSaveAnswerFallsBackToThePayload()

	/**
	 * A registry URL records the store as configured, trimmed.
	 *
	 * @return void
	 */
	public function testARegistryUrlRecordsTheStoreAsConfigured(): void {
		$this->integrationStatus->expects($this->once())
			->method('record')
			->with('store', 'configured', 'Registry at https://store.example')
			->willReturn(true);

		$this->subject()->recordStoreStatus(registryUrl: '  https://store.example ');
	}//end testARegistryUrlRecordsTheStoreAsConfigured()

	/**
	 * A blank registry URL records the store as unconfigured.
	 *
	 * @return void
	 */
	public function testABlankRegistryUrlRecordsTheStoreAsUnconfigured(): void {
		$this->integrationStatus->expects($this->once())
			->method('record')
			->with('store', 'unconfigured', 'Not checked yet')
			->willReturn(true);

		$this->subject()->recordStoreStatus(registryUrl: '   ');
	}//end testABlankRegistryUrlRecordsTheStoreAsUnconfigured()
}//end class
