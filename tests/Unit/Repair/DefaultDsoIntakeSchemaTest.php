<?php

/**
 * Tests for the repair step that points DSO intake at integriq's dso_verzoek.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Listener\VergunningaanvraagCreatedListener;
use OCA\Dossiq\Repair\DefaultDsoIntakeSchema;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;

/**
 * A fresh install with integriq makes DSO cases without an admin setting a key.
 *
 * The key is `dso_vergunningaanvraag_schema`. "Never set" is the key being
 * ABSENT; an admin who empties it has said "off", and that is kept.
 *
 * @covers \OCA\Dossiq\Repair\DefaultDsoIntakeSchema
 * @uses   \OCA\Dossiq\Support\FleetAppId
 * @uses   \OCA\Dossiq\Listener\VergunningaanvraagCreatedListener
 * @uses   \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class DefaultDsoIntakeSchemaTest extends TestCase {

	use MakesBackgroundServiceAccount;

	/**
	 * The app config, as stored rows.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * How many times a value was written.
	 *
	 * @var integer
	 */
	private int $writes = 0;

	/**
	 * The ids of the apps that answer as installed.
	 *
	 * @var list<string>
	 */
	private array $installed = ['openregister', 'integriq'];

	/**
	 * Register slug to the schema ids that register holds.
	 *
	 * @var array<string, list<int>>
	 */
	private array $registers = ['integriq' => [12, 60, 61]];

	/**
	 * Schema id to slug.
	 *
	 * @var array<int, string>
	 */
	private array $schemas = [12 => 'source', 60 => 'dso_verzoek', 61 => 'dso_activity_mapping'];

	/**
	 * Whether the container answers OpenRegister's mappers.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * Fill the key when it was never set and integriq ships the schema.
	 *
	 * @return void
	 */
	public function testFillsTheKeyWithIntegriqsDsoVerzoekId(): void {
		$this->run_();

		$this->assertSame(['dso_vergunningaanvraag_schema' => '60'], $this->stored);
	}//end testFillsTheKeyWithIntegriqsDsoVerzoekId()

	/**
	 * The value written is the one the listener matches a mapped verzoek on.
	 *
	 * @return void
	 */
	public function testTheWrittenValueMakesTheListenerCreateTheCase(): void {
		$this->run_();

		$dsoCaseService = $this->createMock(DsoCaseService::class);
		$dsoCaseService->expects($this->once())
			->method('createZaakFromVergunningaanvraag')
			->with('verzoek-default-schema-1', $this->anything());

		$listener = new VergunningaanvraagCreatedListener(
			appConfig: $this->appConfig(),
			dsoCaseService: $dsoCaseService,
			logger: $this->createMock(LoggerInterface::class),
			serviceAccount: $this->backgroundAccount(),
		);

		// OpenRegister stores an object's schema as the schema's id.
		$entity = new ObjectEntity();
		$entity->setUuid('verzoek-default-schema-1');
		$entity->setSchema('60');
		$entity->setObject(['status' => 'mapped', 'mappedCaseTypes' => ['DSC-MILIEU']]);

		$listener->handle(event: new ObjectUpdatedEvent($entity));
	}//end testTheWrittenValueMakesTheListenerCreateTheCase()

	/**
	 * An admin who emptied the key turned DSO intake off; keep it off.
	 *
	 * @return void
	 */
	public function testKeepsAnEmptyValueAnAdminSet(): void {
		$this->stored = ['dso_vergunningaanvraag_schema' => ''];

		$this->run_();

		$this->assertSame(['dso_vergunningaanvraag_schema' => ''], $this->stored);
		$this->assertSame(0, $this->writes);
	}//end testKeepsAnEmptyValueAnAdminSet()

	/**
	 * An admin who chose another schema keeps it.
	 *
	 * @return void
	 */
	public function testKeepsAValueAnAdminSet(): void {
		$this->stored = ['dso_vergunningaanvraag_schema' => '42'];

		$this->run_();

		$this->assertSame(['dso_vergunningaanvraag_schema' => '42'], $this->stored);
		$this->assertSame(0, $this->writes);
	}//end testKeepsAValueAnAdminSet()

	/**
	 * A second run writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$this->run_();
		$this->run_();

		$this->assertSame(1, $this->writes);
		$this->assertSame(['dso_vergunningaanvraag_schema' => '60'], $this->stored);
	}//end testASecondRunWritesNothing()

	/**
	 * Without integriq the key stays absent, so a later install still fills it.
	 *
	 * @return void
	 */
	public function testLeavesTheKeyAbsentWithoutIntegriq(): void {
		$this->installed = ['openregister'];

		$this->run_();

		$this->assertSame([], $this->stored);
	}//end testLeavesTheKeyAbsentWithoutIntegriq()

	/**
	 * integriq under its old id, with its register under the old slug.
	 *
	 * @return void
	 */
	public function testFindsTheSchemaUnderTheOldRegisterSlug(): void {
		$this->installed = ['openregister', 'openconnector'];
		$this->registers = ['openconnector' => [60]];

		$this->run_();

		$this->assertSame(['dso_vergunningaanvraag_schema' => '60'], $this->stored);
	}//end testFindsTheSchemaUnderTheOldRegisterSlug()

	/**
	 * integriq installed, its register not imported yet: nothing written, no throw.
	 *
	 * @return void
	 */
	public function testLeavesTheKeyAbsentWhenTheRegisterIsMissing(): void {
		$this->registers = [];

		$this->run_();

		$this->assertSame([], $this->stored);
	}//end testLeavesTheKeyAbsentWhenTheRegisterIsMissing()

	/**
	 * A dso_verzoek in another register is not integriq's and is not picked.
	 *
	 * @return void
	 */
	public function testLeavesTheKeyAbsentWhenTheRegisterHasNoDsoVerzoek(): void {
		$this->registers = ['integriq' => [12, 61], 'other' => [60]];

		$this->run_();

		$this->assertSame([], $this->stored);
	}//end testLeavesTheKeyAbsentWhenTheRegisterHasNoDsoVerzoek()

	/**
	 * Without OpenRegister's mappers the step says so and does not throw.
	 *
	 * @return void
	 */
	public function testDoesNotThrowWithoutOpenRegister(): void {
		$this->openRegister = false;

		$this->run_();

		$this->assertSame([], $this->stored);
	}//end testDoesNotThrowWithoutOpenRegister()

	/**
	 * Registered for a fresh install and for an upgrade.
	 *
	 * @return void
	 */
	public function testIsRegisteredForInstallAndUpgrade(): void {
		$xml = new SimpleXMLElement((string)file_get_contents(__DIR__.'/../../../appinfo/info.xml'));
		$class = DefaultDsoIntakeSchema::class;

		foreach (['install', 'post-migration'] as $block) {
			$steps = array_map('strval', $xml->xpath('/info/repair-steps/'.$block.'/step') ?: []);
			$this->assertContains($class, $steps, $class.' is not in <'.$block.'>');
		}
	}//end testIsRegisteredForInstallAndUpgrade()

	/**
	 * Run the step over the fixtures.
	 *
	 * @return void
	 */
	private function run_(): void {
		$step = new DefaultDsoIntakeSchema(
			appConfig: $this->appConfig(),
			appManager: $this->appManager(),
			container: $this->container(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run($this->createMock(IOutput::class));
	}//end run_()

	/**
	 * An app config over $this->stored.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(
			fn (string $app, string $key): bool => $app === 'dossiq' && array_key_exists($key, $this->stored)
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('dossiq', $app);
				$this->stored[$key] = $value;
				$this->writes++;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * An app manager answering $this->installed.
	 *
	 * @return IAppManager
	 */
	private function appManager(): IAppManager {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(
			fn (string $appId): bool => in_array($appId, $this->installed, true)
		);

		return $manager;
	}//end appManager()

	/**
	 * A container answering OpenRegister's two mappers over the fixtures.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$registers = $this->registers;
		$schemas = $this->schemas;

		$registerMapper = new class($registers) {
			/**
			 * @param array<string, list<int>> $registers Slug to schema ids.
			 */
			public function __construct(private array $registers) {
			}

			/**
			 * @param string|int $id The slug.
			 *
			 * @return object The register.
			 */
			public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if (isset($this->registers[(string)$id]) === false) {
					throw new RuntimeException('Register not found');
				}

				$ids = $this->registers[(string)$id];
				return new class($ids) {
					/**
					 * @param list<int> $ids The schema ids.
					 */
					public function __construct(private array $ids) {
					}

					/**
					 * @return list<int>
					 */
					public function getSchemas(): array {
						return $this->ids;
					}
				};
			}
		};

		$schemaMapper = new class($schemas) {
			/**
			 * @param array<int, string> $schemas Id to slug.
			 */
			public function __construct(private array $schemas) {
			}

			/**
			 * @param string    $slug      The slug.
			 * @param list<int> $schemaIds The ids to look in.
			 *
			 * @return object|null The schema.
			 */
			public function findBySlugInIds(string $slug, array $schemaIds): ?object {
				foreach ($schemaIds as $id) {
					if (($this->schemas[(int)$id] ?? null) === $slug) {
						return new class((int)$id) {
							public function __construct(private int $id) {
							}

							public function getId(): int {
								return $this->id;
							}
						};
					}
				}

				return null;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($registerMapper, $schemaMapper): object {
				if ($this->openRegister === false) {
					throw new RuntimeException('No such service '.$name);
				}

				return match ($name) {
					'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
					'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
					default => throw new RuntimeException('No such service '.$name),
				};
			}
		);

		return $container;
	}//end container()
}//end class
