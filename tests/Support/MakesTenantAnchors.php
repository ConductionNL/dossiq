<?php

/**
 * The real tenant anchor chain over an in-memory OpenRegister.
 *
 * `TenantService::ensureAuditAnchor()` reads the Organisation through the real
 * `TenantOrganisationResolver` and writes the tenant object through
 * OpenRegister's ObjectService. `TenantAuditTrailService` finds that object
 * and writes its audit row through OpenRegister's AuditTrailMapper. Only the
 * three OpenRegister seams are doubled: the object store, the Organisation
 * mapper and the audit trail mapper, each with the signature of the real one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use DateTime;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\TenantOrganisationResolver;
use OCA\Dossiq\Service\TenantService;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

trait MakesTenantAnchors {
	/**
	 * The shared object store.
	 *
	 * @var RefusableRegister
	 */
	private RefusableRegister $anchorStore;

	/**
	 * The audit rows written.
	 *
	 * @var RecordingAuditTrailMapper
	 */
	private RecordingAuditTrailMapper $anchorTrail;

	/**
	 * The Organisations OpenRegister holds, by uuid.
	 *
	 * @var array<string, Organisation>
	 */
	private array $organisations = [];

	/**
	 * OpenRegister's task engine, where onboarding steps live.
	 *
	 * @var InMemoryTaskEngine
	 */
	private InMemoryTaskEngine $engine;

	/**
	 * Every error logged by the chain, message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $anchorErrors = [];

	/**
	 * Start an empty store, trail and Organisation table.
	 *
	 * @return void
	 */
	private function startAnchorStore(): void {
		$this->anchorStore = new RefusableRegister();
		$this->anchorTrail = new RecordingAuditTrailMapper();
		$this->organisations = [];
		$this->anchorErrors = [];
		$this->engine = new InMemoryTaskEngine();
	}//end startAnchorStore()

	/**
	 * Put an Organisation in OpenRegister.
	 *
	 * @param string $uuid The uuid.
	 * @param string $slug The slug.
	 * @param string $name The name.
	 *
	 * @return void
	 */
	private function givenOrganisation(string $uuid, string $slug, string $name): void {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setSlug($slug);
		$organisation->setName($name);
		$organisation->setStatus('active');
		$organisation->setCreated(new DateTime('2026-10-01T09:00:00+00:00'));
		$this->organisations[$uuid] = $organisation;
	}//end givenOrganisation()

	/**
	 * A container that answers the three OpenRegister seams.
	 *
	 * @return ContainerInterface The container.
	 */
	private function anchorContainer(): ContainerInterface {
		$objects = new EntityAnsweringRegister(register: $this->anchorStore);
		$trail = $this->anchorTrail;
		$engine = $this->engine;

		$mapper = $this->createMock(OrganisationByUuidStub::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid): Organisation {
				if (array_key_exists($uuid, $this->organisations) === false) {
					throw new RuntimeException('no such organisation');
				}

				return $this->organisations[$uuid];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $trail, $mapper, $engine): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\Task\\TaskService',
					'OCA\\OpenRegister\\Service\\Task\\TaskInboxService',
					'OCA\\OpenRegister\\Service\\Task\\TaskFormCompletion' => $engine,
					'OCA\\OpenRegister\\Service\\ObjectService' => $objects,
					'OCA\\OpenRegister\\Db\\AuditTrailMapper' => $trail,
					'OCA\\OpenRegister\\Db\\OrganisationMapper' => $mapper,
					default => throw new RuntimeException('unknown service '.$id),
				};
			}
		);

		return $container;
	}//end anchorContainer()

	/**
	 * An app manager that reports OpenRegister installed, or not.
	 *
	 * @param bool $openRegister Whether OpenRegister is installed.
	 *
	 * @return IAppManager The app manager.
	 */
	private function anchorApps(bool $openRegister = true): IAppManager {
		$installed = [];
		if ($openRegister === true) {
			$installed = ['openregister'];
		}

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		return $apps;
	}//end anchorApps()

	/**
	 * A logger that records every error.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function anchorLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->anchorErrors[] = [(string) $message, $context];
			}
		);

		return $logger;
	}//end anchorLogger()

	/**
	 * The real TenantService over the shared store.
	 *
	 * @param bool $openRegister Whether OpenRegister is installed.
	 *
	 * @return TenantService The service.
	 */
	private function realTenantService(bool $openRegister = true): TenantService {
		$container = $this->anchorContainer();
		$apps = $this->anchorApps(openRegister: $openRegister);
		$logger = $this->anchorLogger();


		return new TenantService(
			groupManager: $this->createMock(IGroupManager::class),
			organisations: new TenantOrganisationResolver(appManager: $apps, container: $container, logger: $logger),
			appManager: $apps,
			container: $container,
			logger: $logger,
		);
	}//end realTenantService()

	/**
	 * The real OnboardingSteps over the in-memory engine.
	 *
	 * @return OnboardingSteps The steps.
	 */
	private function realOnboardingSteps(): OnboardingSteps {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$container = $this->anchorContainer();
		$logger = $this->anchorLogger();

		return new OnboardingSteps(
			settings: $settings,
			container: $container,
			logger: $logger,
			inboxQuery: $this->anchorInboxQuery(settings: $settings, container: $container, logger: $logger)
		);
	}//end realOnboardingSteps()

	/**
	 * The real EngineInboxQuery, reading the in-memory engine.
	 *
	 * Only the lookup of OpenRegister's inbox service is replaced, because
	 * OpenRegister is not installed in dossiq's test run; the criteria are
	 * built and the rows unwrapped by the production code.
	 *
	 * @param SettingsService    $settings  OpenRegister availability.
	 * @param ContainerInterface $container The anchor container.
	 * @param LoggerInterface    $logger    The anchor logger.
	 *
	 * @return EngineInboxQuery The query.
	 */
	private function anchorInboxQuery(SettingsService $settings, ContainerInterface $container, LoggerInterface $logger): EngineInboxQuery {
		return new class($settings, $container, $logger) extends EngineInboxQuery {
			/**
			 * Constructor.
			 *
			 * @param SettingsService    $settings  OpenRegister availability.
			 * @param ContainerInterface $engine    The anchor container.
			 * @param LoggerInterface    $logger    The anchor logger.
			 */
			public function __construct(SettingsService $settings, private ContainerInterface $engine, LoggerInterface $logger) {
				parent::__construct(settings: $settings, container: $engine, logger: $logger);
			}

			/**
			 * The in-memory engine's inbox.
			 *
			 * @return object|null The inbox.
			 */
			protected function resolveInbox(): ?object {
				return $this->engine->get('OCA\\OpenRegister\\Service\\Task\\TaskInboxService');
			}
		};
	}//end anchorInboxQuery()

	/**
	 * The real TenantAuditTrailService over the shared store and trail.
	 *
	 * @return TenantAuditTrailService The service.
	 */
	private function realTenantAuditTrail(): TenantAuditTrailService {
		return new TenantAuditTrailService(
			logger: $this->anchorLogger(),
			appManager: $this->anchorApps(),
			container: $this->anchorContainer(),
		);
	}//end realTenantAuditTrail()

	/**
	 * The tenant objects in the store, each carrying its uuid as `id`.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function storedAnchors(): array {
		return $this->anchorStore->all(schema: 'tenant');
	}//end storedAnchors()
}//end trait
