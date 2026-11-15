<?php

/**
 * Unit test for BeschikkingAdapterRegistrar.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registrar;

use OCA\Dossiq\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Dossiq\Service\Beschikking\LibresignResultAssembler;
use OCA\Dossiq\Service\Beschikking\LibresignSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockSigningAdapter;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\Registrar\BeschikkingAdapterRegistrar;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The beschikking registrar picks LibreSign when it is enabled, the stub otherwise, and aliases archival.
 *
 * @covers \OCA\Dossiq\Service\Registrar\BeschikkingAdapterRegistrar
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignApiClient
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignResultAssembler
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignSigningAdapter
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignStatusMapper
 */
class BeschikkingAdapterRegistrarTest extends TestCase {

	/**
	 * The signing factory the registrar bound.
	 *
	 * @var callable|null
	 */
	private $factory = null;

	/**
	 * The aliases the registrar bound.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * The ids the container was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Run the registrar against a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->assertSame(SigningAdapterInterface::class, $name);
				$this->factory = $factory;
			}
		);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);

		(new BeschikkingAdapterRegistrar())->register(context: $context);
	}//end setUp()

	/**
	 * A container holding the given services, recording every id asked for.
	 *
	 * @param array<string, object> $services The services by id.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services): object {
				$this->asked[] = $id;
				return $services[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * An app manager answering whether LibreSign is enabled.
	 *
	 * @param bool $enabled Whether LibreSign is enabled.
	 *
	 * @return IAppManager The app manager.
	 */
	private function appManager(bool $enabled): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('isEnabledForUser')->with('libresign')->willReturn($enabled);

		return $appManager;
	}//end appManager()

	/**
	 * Archival is aliased onto the OpenRegister archival adapter.
	 *
	 * @return void
	 */
	public function testArchivalIsAliasedOntoOpenRegister(): void {
		$this->assertSame([ArchivalAdapterInterface::class => OpenRegisterArchivalAdapter::class], $this->aliases);
	}//end testArchivalIsAliasedOntoOpenRegister()

	/**
	 * Without LibreSign the stub runs, and the log warns in the translated text.
	 *
	 * @return void
	 */
	public function testWithoutLibresignTheStubRunsAndTheLogSaysSo(): void {
		$stub = new MockSigningAdapter();
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')
			->with($this->stringStartsWith('LibreSign is not installed or enabled.'))
			->willReturn('vertaald');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('vertaald', ['app' => 'dossiq']);

		$adapter = ($this->factory)(
			$this->container(
				services: [
					'OCP\\App\\IAppManager' => $this->appManager(enabled: false),
					'Psr\\Log\\LoggerInterface' => $logger,
					'OCP\\IL10N' => $l10n,
					MockSigningAdapter::class => $stub,
				]
			)
		);

		$this->assertSame($stub, $adapter);
		$this->assertNotContains('OCP\\Http\\Client\\IClientService', $this->asked);
	}//end testWithoutLibresignTheStubRunsAndTheLogSaysSo()

	/**
	 * With LibreSign enabled the LibreSign adapter is built, and nothing warns.
	 *
	 * @return void
	 */
	public function testWithLibresignTheLibresignAdapterIsBuilt(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$readers = $this->createMock(RestrictedDocumentReaders::class);
		$readers->expects($this->once())->method('inheritFromId')
			->with('orig-1', 42, 'alice')
			->willReturn(true);

		$adapter = ($this->factory)(
			$this->container(
				services: [
					'OCP\\App\\IAppManager' => $this->appManager(enabled: true),
					'OCP\\Http\\Client\\IClientService' => $this->createMock(IClientService::class),
					'OCP\\IURLGenerator' => $this->createMock(IURLGenerator::class),
					'OCP\\IAppConfig' => $this->createMock(IAppConfig::class),
					'Psr\\Log\\LoggerInterface' => $logger,
					'OCP\\IUserManager' => $this->createMock(IUserManager::class),
					'OCP\\Files\\IRootFolder' => $this->createMock(IRootFolder::class),
					ZgwDocumentService::class => $this->createMock(ZgwDocumentService::class),
					RestrictedDocumentReaders::class => $readers,
				]
			)
		);

		$this->assertInstanceOf(LibresignSigningAdapter::class, $adapter);
		$this->assertNotContains(MockSigningAdapter::class, $this->asked);
		$this->assertNotContains(RestrictedDocumentReaders::class, $this->asked);

		// The signed-version hook resolves the readers lazily and passes the ids through unchanged.
		$assembler = (new ReflectionProperty(LibresignSigningAdapter::class, 'assembler'))->getValue($adapter);
		$this->assertInstanceOf(LibresignResultAssembler::class, $assembler);
		$hook = (new ReflectionProperty(LibresignResultAssembler::class, 'signedVersionOf'))->getValue($assembler);
		$this->assertTrue($hook('orig-1', 42, 'alice'));
		$this->assertContains(RestrictedDocumentReaders::class, $this->asked);
	}//end testWithLibresignTheLibresignAdapterIsBuilt()
}//end class
