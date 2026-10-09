<?php

/**
 * The real TenantMigrationService over a doubled OpenRegister.
 *
 * The two OpenRegister seams the migration calls are doubled with their real
 * method names: the ObjectService's `searchObjectsBySlug()` for the legacy
 * tenant rows, and the OrganisationMapper's `findByUuid()`, `findBySlug()`
 * (both throw when there is no row, as the real mapper does), `insert()` and
 * `update()`. The mapper records every write, so a test can say nothing was
 * written.
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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantMigrationService;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

trait MakesTenantMigration {
	/**
	 * The doubled OrganisationMapper: rows by uuid and slug, and every write.
	 *
	 * @var object
	 */
	private object $organisations;

	/**
	 * Every warning the migration or the step logged.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $migrationWarnings = [];

	/**
	 * An Organisation as OpenRegister stores it.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $slug   The slug.
	 * @param string $status The lifecycle status.
	 *
	 * @return Organisation The organisation.
	 */
	private function storedOrganisation(string $uuid, string $slug, string $status = 'active'): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setSlug($slug);
		$organisation->setStatus($status);
		$organisation->setName($slug);

		return $organisation;
	}//end storedOrganisation()

	/**
	 * A mapper over the given organisations.
	 *
	 * @param array<int, Organisation> $existing The stored organisations.
	 *
	 * @return void
	 */
	private function startOrganisations(array $existing): void {
		$this->organisations = new class($existing) {
			/**
			 * @var array<string, Organisation>
			 */
			public array $byUuid = [];

			/**
			 * @var array<string, Organisation>
			 */
			public array $bySlug = [];

			/**
			 * @var array<int, Organisation>
			 */
			public array $inserted = [];

			/**
			 * @var array<int, Organisation>
			 */
			public array $updated = [];

			/**
			 * @param array<int, Organisation> $existing The stored organisations.
			 */
			public function __construct(array $existing) {
				foreach ($existing as $organisation) {
					$this->byUuid[(string) $organisation->getUuid()] = $organisation;
					$this->bySlug[(string) $organisation->getSlug()] = $organisation;
				}
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return Organisation The organisation.
			 */
			public function findByUuid(string $uuid): Organisation {
				return $this->byUuid[$uuid] ?? throw new RuntimeException('no such organisation');
			}

			/**
			 * @param string $slug The slug.
			 *
			 * @return Organisation The organisation.
			 */
			public function findBySlug(string $slug): Organisation {
				return $this->bySlug[$slug] ?? throw new RuntimeException('no such organisation');
			}

			/**
			 * @param Organisation $organisation The organisation.
			 *
			 * @return Organisation The organisation.
			 */
			public function insert(Organisation $organisation): Organisation {
				$this->inserted[] = $organisation;
				$this->byUuid[(string) $organisation->getUuid()] = $organisation;
				$this->bySlug[(string) $organisation->getSlug()] = $organisation;

				return $organisation;
			}

			/**
			 * @param Organisation $organisation The organisation.
			 *
			 * @return Organisation The organisation.
			 */
			public function update(Organisation $organisation): Organisation {
				$this->updated[] = $organisation;

				return $organisation;
			}
		};
	}//end startOrganisations()

	/**
	 * The real migration over the given legacy tenant rows.
	 *
	 * @param array<int, array<string, mixed>> $tenants The legacy tenant rows.
	 *
	 * @return TenantMigrationService The migration.
	 */
	private function realMigration(array $tenants): TenantMigrationService {
		$objects = new class($tenants) {
			/**
			 * @param array<int, array<string, mixed>> $tenants The rows.
			 */
			public function __construct(private readonly array $tenants) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $schema === 'tenant' ? $this->tenants : [];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$organisations = $this->organisations;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === 'OCA\\OpenRegister\\Db\\OrganisationMapper'
				? $organisations
				: throw new RuntimeException('unknown service '.$id)
		);

		return new TenantMigrationService($settings, $container, $apps, $this->recordingLogger());
	}//end realMigration()

	/**
	 * A logger that keeps every warning.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function recordingLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->migrationWarnings[] = [(string) $message, $context];
			}
		);

		return $logger;
	}//end recordingLogger()
}//end trait
