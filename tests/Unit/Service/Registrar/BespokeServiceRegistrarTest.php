<?php

/**
 * Unit test for BespokeServiceRegistrar.
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

use OCA\Dossiq\Controller\DashboardController;
use OCA\Dossiq\Controller\SettingsController;
use OCA\Dossiq\Controller\Support\AdminCaller;
use OCA\Dossiq\Kernel\Contracts\PackageAdminTabsInterface;
use OCA\Dossiq\Kernel\Contracts\QueueUrgencySettingsInterface;
use OCA\Dossiq\Repair\InitializeSettings;
use OCA\Dossiq\Sections\SettingsSection;
use OCA\Dossiq\Service\Access\CaseFieldRoleProjector;
use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\Registrar\BespokeServiceRegistrar;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The bespoke registrar binds its six hand-wired services, and the settings ones build.
 *
 * @covers \OCA\Dossiq\Service\Registrar\BespokeServiceRegistrar
 * @uses \OCA\Dossiq\Controller\DashboardController
 * @uses \OCA\Dossiq\Controller\SettingsController
 * @uses \OCA\Dossiq\Controller\Support\AdminCaller
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 * @uses \OCA\Dossiq\Repair\InitializeSettings
 * @uses \OCA\Dossiq\Sections\SettingsSection
 */
class BespokeServiceRegistrarTest extends TestCase {

	/**
	 * The factories, by service name, in registration order.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Run the registrar against a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);

		(new BespokeServiceRegistrar())->register(context: $context);
	}//end setUp()

	/**
	 * A container holding the given services.
	 *
	 * @param array<string, object> $services The services by id.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $services[$id]
		);

		return $container;
	}//end container()

	/**
	 * All six services are registered, in their declared order.
	 *
	 * @return void
	 */
	public function testSixServicesAreRegisteredInOrder(): void {
		$this->assertSame(
			[
				DashboardController::class,
				SettingsController::class,
				SettingsService::class,
				InitializeSettings::class,
				AdminSettings::class,
				SettingsSection::class,
			],
			array_keys($this->factories)
		);
	}//end testSixServicesAreRegisteredInOrder()

	/**
	 * The settings service factory builds a settings service from the container.
	 *
	 * @return void
	 */
	public function testTheSettingsServiceIsBuilt(): void {
		$service = $this->factories[SettingsService::class](
			$this->container(
				services: [
					'OCP\\IAppConfig' => $this->createMock(IAppConfig::class),
					'OCP\\App\\IAppManager' => $this->createMock(IAppManager::class),
					'Psr\\Log\\LoggerInterface' => $this->createMock(LoggerInterface::class),
				]
			)
		);

		$this->assertInstanceOf(SettingsService::class, $service);
	}//end testTheSettingsServiceIsBuilt()

	/**
	 * The repair step is built from the settings service and both access projectors.
	 *
	 * @return void
	 */
	public function testTheInitializeSettingsRepairStepIsBuilt(): void {
		$asked = [];
		$services = [
			SettingsService::class => $this->createMock(SettingsService::class),
			CaseFieldRoleProjector::class => $this->createMock(CaseFieldRoleProjector::class),
			CaseTeamAccessProjector::class => $this->createMock(CaseTeamAccessProjector::class),
			'Psr\\Log\\LoggerInterface' => $this->createMock(LoggerInterface::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, &$asked): object {
				$asked[] = $id;
				return $services[$id];
			}
		);

		$step = $this->factories[InitializeSettings::class]($container);

		$this->assertInstanceOf(InitializeSettings::class, $step);
		$this->assertSame(array_keys($services), $asked);
	}//end testTheInitializeSettingsRepairStepIsBuilt()

	/**
	 * A container holding the given services that records the ids it was asked for.
	 *
	 * @param array<string, object> $services The services by id.
	 * @param array<int, string>    $asked    Filled with the ids asked, in order.
	 *
	 * @return ContainerInterface The container.
	 */
	private function recordingContainer(array $services, array &$asked): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, &$asked): object {
				$asked[] = $id;
				return $services[$id];
			}
		);

		return $container;
	}//end recordingContainer()

	/**
	 * The dashboard controller is built from the request, initial state, app config and event dispatcher.
	 *
	 * @return void
	 */
	public function testTheDashboardControllerIsBuilt(): void {
		$asked = [];
		$controller = $this->factories[DashboardController::class](
			$this->recordingContainer(
				services: [
					'OCP\\IRequest' => $this->createMock(IRequest::class),
					'OCP\\AppFramework\\Services\\IInitialState' => $this->createMock(IInitialState::class),
					'OCP\\IAppConfig' => $this->createMock(IAppConfig::class),
					'OCP\\EventDispatcher\\IEventDispatcher' => $this->createMock(IEventDispatcher::class),
				],
				asked: $asked
			)
		);

		$this->assertInstanceOf(DashboardController::class, $controller);
		$this->assertSame(
			['OCP\\IRequest', 'OCP\\AppFramework\\Services\\IInitialState', 'OCP\\IAppConfig', 'OCP\\EventDispatcher\\IEventDispatcher'],
			$asked
		);
	}//end testTheDashboardControllerIsBuilt()

	/**
	 * The settings controller is built from the container itself and the settings service, the admin caller and translator.
	 *
	 * @return void
	 */
	public function testTheSettingsControllerIsBuilt(): void {
		$asked = [];
		$controller = $this->factories[SettingsController::class](
			$this->recordingContainer(
				services: [
					'OCP\\IRequest' => $this->createMock(IRequest::class),
					'OCP\\App\\IAppManager' => $this->createMock(IAppManager::class),
					SettingsService::class => $this->createMock(SettingsService::class),
					AdminCaller::class => new AdminCaller(userSession: $this->createMock(originalClassName: IUserSession::class), groupManager: $this->createMock(originalClassName: IGroupManager::class)),
					'OCP\\IL10N' => $this->createMock(IL10N::class),
				],
				asked: $asked
			)
		);

		$this->assertInstanceOf(SettingsController::class, $controller);
		$this->assertSame(
			['OCP\\IRequest', 'OCP\\App\\IAppManager', SettingsService::class, AdminCaller::class, 'OCP\\IL10N'],
			$asked
		);
	}//end testTheSettingsControllerIsBuilt()

	/**
	 * The settings section is built from the translator and the URL generator.
	 *
	 * @return void
	 */
	public function testTheSettingsSectionIsBuilt(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')->with('dossiq', 'app-dark.svg')->willReturn('/icon.svg');

		$section = $this->factories[SettingsSection::class](
			$this->container(services: ['OCP\\IL10N' => $l10n, 'OCP\\IURLGenerator' => $urls])
		);

		$this->assertInstanceOf(SettingsSection::class, $section);
		$this->assertSame('/icon.svg', $section->getIcon());
	}//end testTheSettingsSectionIsBuilt()

	/**
	 * The admin settings factory hands the constructor every argument it
	 * declares, so the admin settings pages build.
	 *
	 * @return void
	 */
	public function testTheAdminSettingsAreBuilt(): void {
		$settings = $this->factories[AdminSettings::class](
			$this->container(
				services: [
					'OCP\\App\\IAppManager' => $this->createMock(originalClassName: IAppManager::class),
					'OCP\\AppFramework\\Services\\IInitialState' => $this->createMock(originalClassName: IInitialState::class),
					'OCA\\Dossiq\\Service\\SettingsService' => $this->createMock(originalClassName: SettingsService::class),
					'OCA\\Dossiq\\Service\\Queue\\QueueUrgencySettings' => $this->createMock(originalClassName: QueueUrgencySettingsInterface::class),
					'OCP\\ServerVersion' => (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
					'OCA\\Dossiq\\Kernel\\Contracts\\PackageAdminTabsInterface' => $this->createMock(originalClassName: PackageAdminTabsInterface::class),
				]
			)
		);

		$this->assertInstanceOf(expected: AdminSettings::class, actual: $settings);
	}//end testTheAdminSettingsAreBuilt()
}//end class
