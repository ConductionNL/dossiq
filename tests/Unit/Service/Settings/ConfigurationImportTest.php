<?php

/**
 * ConfigurationImport: the import of the app's register into OpenRegister, and
 * the answer it gives for each way that import can go.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\ConfigurationImport;
use OCA\Dossiq\Service\Settings\OpenRegisterBridge;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\RegisterStorageDeclaration;
use OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler;
use OCA\Dossiq\Service\Settings\SchemaKeyReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TypeError;

/**
 * Loading the configuration answers success with what was mapped, or names why it did not import.
 */
#[CoversClass(ConfigurationImport::class)]
#[UsesClass(OpenRegisterBridge::class)]
#[UsesClass(RegisterFragmentMerger::class)]
#[UsesClass(RegisterStorageDeclaration::class)]
#[UsesClass(SchemaKeyReconciler::class)]
#[UsesClass(SchemaAnnotationReconciler::class)]
#[UsesClass(SchemaSlugResolver::class)]
final class ConfigurationImportTest extends TestCase {

	private IAppManager&MockObject $apps;
	private IAppConfig&MockObject $appConfig;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	/**
	 * What the configuration service double was asked to import.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $imported = null;

	protected function setUp(): void {
		$this->apps      = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The importer, with OpenRegister installed or not.
	 *
	 * @param bool $openRegister Whether OpenRegister is installed.
	 *
	 * @return ConfigurationImport
	 */
	private function importer(bool $openRegister = true): ConfigurationImport {
		$this->apps->method('isEnabledForUser')->with('openregister')->willReturn($openRegister);
		$this->apps->method('isInstalled')->with('openregister')->willReturn($openRegister);

		return new ConfigurationImport(
			openRegister: new OpenRegisterBridge(appManager: $this->apps, container: $this->container, logger: $this->logger),
			appConfig: $this->appConfig,
			container: $this->container,
			logger: $this->logger
		);
	}//end importer()

	/**
	 * The container hands out this configuration service; the schema mapper is absent.
	 *
	 * @param object $configurationService The configuration service double.
	 *
	 * @return void
	 */
	private function serves(object $configurationService): void {
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($configurationService): object {
				if ($id === 'OCA\OpenRegister\Service\ConfigurationService') {
					return $configurationService;
				}

				throw new RuntimeException('no ' . $id);
			}
		);
	}//end serves()

	/**
	 * A configuration service that records the import and answers with $result, or throws $failure.
	 *
	 * @param array<string, mixed> $result  The import result.
	 * @param \Throwable|null      $failure What the import throws, if anything.
	 *
	 * @return object
	 */
	private function configurationService(array $result, ?\Throwable $failure = null): object {
		$imported = &$this->imported;

		return new class($result, $failure, $imported) {
			public function __construct(private array $result, private ?\Throwable $failure, private ?array &$imported) {
			}

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->imported = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->result;
			}
		};
	}//end configurationService()

	/**
	 * The version the shipped register declares.
	 *
	 * @return string
	 */
	private static function shippedVersion(): string {
		$json = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/dossiq_register.json'), true);
		return (string)$json['info']['version'];
	}//end shippedVersion()

	public function testWithoutOpenRegisterNothingIsImported(): void {
		$this->container->expects($this->never())->method('get');

		$importer = $this->importer(openRegister: false);

		$this->assertSame(['success' => false, 'message' => 'OpenRegister is not installed or enabled'], $importer->loadConfiguration());
		$this->assertSame(0, $importer->reconcileSchemaConfig());
		$this->assertSame(0, $importer->reconcileSchemaDeclarativeConfig());
	}//end testWithoutOpenRegisterNothingIsImported()

	public function testAnUnreachableConfigurationServiceIsNamed(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('service missing'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Dossiq: Could not access ConfigurationService', ['exception' => 'service missing']);

		$this->assertSame(
			['success' => false, 'message' => 'Could not access ConfigurationService: service missing'],
			$this->importer()->loadConfiguration()
		);
	}//end testAnUnreachableConfigurationServiceIsNamed()

	public function testTheShippedRegisterIsImportedAndTheMappedSchemasCounted(): void {
		$register = new class {
			public function getId(): int {
				return 23;
			}
		};
		$this->serves($this->configurationService(['registers' => [$register], 'schemas' => []]));
		$set = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$set): bool {
				$set[$key] = $value;
				return true;
			}
		);

		$answer = $this->importer()->loadConfiguration(force: true);

		$this->assertSame('dossiq', $this->imported['appId']);
		$this->assertTrue($this->imported['force']);
		$this->assertSame(self::shippedVersion(), $this->imported['version']);
		$this->assertSame(self::shippedVersion(), $this->imported['data']['info']['version']);
		$this->assertArrayHasKey('components', $this->imported['data']);
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Configuration imported and auto-configured (0 schemas mapped)',
				'version' => self::shippedVersion(),
				'configured' => 0,
				'result' => ['registers' => [$register], 'schemas' => []],
			],
			$answer
		);
		$this->assertSame('23', $set['register']);
	}//end testTheShippedRegisterIsImportedAndTheMappedSchemasCounted()

	public function testAnImportIsNotForcedByDefault(): void {
		$this->serves($this->configurationService([]));

		$this->importer()->loadConfiguration();

		$this->assertFalse($this->imported['force']);
	}//end testAnImportIsNotForcedByDefault()

	public function testAnImportThatThrowsAnErrorIsAnsweredNotThrown(): void {
		$this->serves($this->configurationService([], new TypeError('setSearchable(): Argument #1 must be of type bool')));
		$this->logger->expects($this->once())
			->method('error')
			->with('Dossiq: Configuration import failed', ['exception' => 'setSearchable(): Argument #1 must be of type bool']);

		$this->assertSame(
			['success' => false, 'message' => 'Import failed: setSearchable(): Argument #1 must be of type bool'],
			$this->importer()->loadConfiguration()
		);
	}//end testAnImportThatThrowsAnErrorIsAnsweredNotThrown()

	public function testReconcilingWithoutASchemaMapperWritesNothing(): void {
		$this->serves($this->configurationService([]));
		$this->appConfig->expects($this->never())->method('setValueString');

		$importer = $this->importer();

		$this->assertSame(0, $importer->reconcileSchemaConfig());
		$this->assertSame(0, $importer->reconcileSchemaDeclarativeConfig());
	}//end testReconcilingWithoutASchemaMapperWritesNothing()
}//end class
