<?php

/**
 * Dossiq Load Default ZGW Mappings Repair Step
 *
 * Repair step that loads default ZGW API mapping configurations into IAppConfig.
 * These mappings define how English OpenRegister properties translate to/from
 * Dutch ZGW API properties using Twig templates.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use DateTime;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwMappingServiceInterface;
use OCA\Dossiq\Repair\Support\DefaultMappingTables;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that loads default ZGW API mapping configurations.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class LoadDefaultZgwMappings implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Constructor for LoadDefaultZgwMappings.
	 *
	 * @param ZgwMappingServiceInterface $zgwMappingService The ZGW mapping service
	 * @param SettingsServiceInterface $settingsService The settings service
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwMappingServiceInterface $zgwMappingService,
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getName(): string {
		return 'Load default ZGW API mapping configurations for Dossiq';
	}//end getName()

	/**
	 * Run the repair step to load default ZGW mappings.
	 *
	 * Only loads mappings that do not already exist (does not overwrite).
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		$output->info('Loading default ZGW API mappings...');

		$defaults = $this->registerDefaults(output: $output);
		if ($defaults === null) {
			return;
		}

		$loaded = $this->loadMissingMappings(defaults: $defaults);
		$output->info("Loaded {$loaded} default ZGW mapping configurations.");

		// Patch existing mappings that have known bugs (e.g., Twig renders false as "").
		$this->patchExistingMappings(defaults: $defaults, output: $output);
		$this->seedDefaults(output: $output);

		$this->logger->info(
			'Dossiq: Default ZGW mappings loaded',
			['loaded' => $loaded, 'total' => count(value: $defaults)]
		);
	}//end run()

	/**
	 * The default mappings for the configured register, or null when none is configured yet.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return array<string, array<string, mixed>>|null The defaults by resource key.
	 */
	private function registerDefaults(IOutput $output): ?array {
		$registerId = $this->settingsService->getConfigValue(key: 'register', default: '');
		if ($registerId === '') {
			$output->warning('No Dossiq register configured yet. Skipping ZGW mapping defaults.');
			return null;
		}

		return $this->getDefaultMappings(registerId: $registerId);
	}//end registerDefaults()

	/**
	 * Save every default mapping this instance does not have yet.
	 *
	 * @param array<string, array<string, mixed>> $defaults The defaults by resource key.
	 *
	 * @return int How many were saved.
	 */
	private function loadMissingMappings(array $defaults): int {
		$loaded = 0;
		foreach ($defaults as $resourceKey => $config) {
			if ($this->zgwMappingService->hasMapping($resourceKey) === true) {
				continue;
			}

			$this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $config);
			$loaded++;
		}

		return $loaded;
	}//end loadMissingMappings()

	/**
	 * Seed the default test applicaties and notification channels, each phase warning rather than throwing.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function seedDefaults(IOutput $output): void {
		// The two seeding phases below are conveniences, and a repair step
		// that THROWS aborts the whole install. On a fresh install the schema
		// settings this register was configured with can still be empty, and a
		// lookup against an empty schema context throws — so each phase warns
		// and continues instead of taking the install down with it.
		try {
			// Create default test applicaties via ConsumerMapper.
			$this->createDefaultApplicaties(output: $output);
		} catch (\Throwable $e) {
			$output->warning('Could not create default applicaties: ' . $e->getMessage());
			$this->logger->warning('Dossiq: default applicaties seed failed', ['exception' => $e->getMessage()]);
		}

		try {
			// Create default notification channels.
			$this->createDefaultKanalen(output: $output);
		} catch (\Throwable $e) {
			$output->warning('Could not create default notification channels: ' . $e->getMessage());
			$this->logger->warning('Dossiq: default kanalen seed failed', ['exception' => $e->getMessage()]);
		}
	}//end seedDefaults()

	/**
	 * Patch existing mappings that contain known bugs.
	 *
	 * Some Twig templates don't handle boolean false correctly (Twig renders
	 * false as empty string "", which nullable casts then turn into null).
	 * This method checks existing mappings and updates them if they contain
	 * the buggy template.
	 *
	 * @param array $defaults The default mapping configurations
	 * @param IOutput $output The repair output
	 *
	 * @return void
	 */
	private function patchExistingMappings(array $defaults, IOutput $output): void {
		$this->healEmptySourceSchemas(defaults: $defaults, output: $output);

		// Patch: enkelvoudiginformatieobject indicatieGebruiksrecht Twig template.
		// Old: '{{ usageRightsIndication }}' renders false as "" → ?bool → null.
		// New: uses is same as() to distinguish false from null.
		$eioKey = 'enkelvoudiginformatieobject';
		if ($this->zgwMappingService->hasMapping($eioKey) === true) {
			$existing = $this->zgwMappingService->getMapping($eioKey);
			$oldTpl = '{{ usageRightsIndication }}';
			$current = $existing['propertyMapping']['indicatieGebruiksrecht'] ?? '';
			if ($current === $oldTpl && isset($defaults[$eioKey]) === true) {
				$existing['propertyMapping']['indicatieGebruiksrecht']
					= $defaults[$eioKey]['propertyMapping']['indicatieGebruiksrecht'];
				$this->zgwMappingService->saveMapping(resourceKey: $eioKey, config: $existing);
				$output->info('Patched enkelvoudiginformatieobject mapping: indicatieGebruiksrecht template.');
			}
		}
	}//end patchExistingMappings()

	/**
	 * Give a stored mapping the schema its default now resolves to.
	 *
	 * `run()` skips a resource key that already has a mapping, which is right
	 * for an operator's edits and wrong for the one field no operator chose.
	 * `sourceSchema` is read from `<x>_schema` in settings at the moment the
	 * mapping is first written, so a mapping written before its schema existed
	 * keeps an empty `sourceSchema` for the life of the instance. OpenRegister
	 * then answers `Schema slug "" is not carried by register "dossiq"`, a 400
	 * that names the register and not the mapping.
	 *
	 * That happened to `catalogus`: dossiq shipped no catalogue schema at all,
	 * so every `/api/zgw/catalogi/v1/catalogussen` write 400ed, and the VNG
	 * contract collections lost their whole setUp behind it.
	 *
	 * Only an EMPTY value is filled in. A mapping pointed at a different schema
	 * on purpose is left exactly as it is.
	 *
	 * @param array $defaults The default mapping configurations
	 * @param IOutput $output The repair output
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function healEmptySourceSchemas(array $defaults, IOutput $output): void {
		$healed = 0;

		foreach ($defaults as $resourceKey => $config) {
			$existing = $this->healedMapping(resourceKey: $resourceKey, config: $config);
			if ($existing === null) {
				continue;
			}

			$this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $existing);
			$healed++;
			$output->info("Filled in the empty sourceSchema on the {$resourceKey} ZGW mapping.");
		}

		$this->logHealed(healed: $healed);
	}//end healEmptySourceSchemas()

	/**
	 * The stored mapping with the schema its default resolves to, when it has none yet.
	 *
	 * @param string               $resourceKey The resource key.
	 * @param array<string, mixed> $config      The default mapping.
	 *
	 * @return array<string, mixed>|null The mapping to save, or null when there is nothing to fill in.
	 */
	private function healedMapping(string $resourceKey, array $config): ?array {
		$resolved = (string)($config['sourceSchema'] ?? '');
		if ($resolved === '') {
			return null;
		}

		$existing = $this->storedMappingWithoutSchema(resourceKey: $resourceKey);
		if ($existing === null) {
			return null;
		}

		$existing['sourceSchema'] = $resolved;

		return $existing;
	}//end healedMapping()

	/**
	 * The stored mapping of a resource, when it exists and its sourceSchema is empty.
	 *
	 * @param string $resourceKey The resource key.
	 *
	 * @return array<string, mixed>|null The mapping, or null.
	 */
	private function storedMappingWithoutSchema(string $resourceKey): ?array {
		if ($this->zgwMappingService->hasMapping($resourceKey) === false) {
			return null;
		}

		$existing = $this->zgwMappingService->getMapping($resourceKey);
		if ($existing === null || (string)($existing['sourceSchema'] ?? '') !== '') {
			return null;
		}

		return $existing;
	}//end storedMappingWithoutSchema()

	/**
	 * Log how many mappings had their empty schema filled in, when any did.
	 *
	 * @param int $healed The number filled in.
	 *
	 * @return void
	 */
	private function logHealed(int $healed): void {
		if ($healed > 0) {
			$this->logger->info(
				'Dossiq: filled in empty ZGW mapping schemas',
				['healed' => $healed]
			);
		}
	}//end logHealed()

	/**
	 * Get the default mapping configurations for all 12 ZGW resources.
	 *
	 * @param string $registerId The Dossiq register ID
	 *
	 * @return array<string, array> Mapping configurations keyed by resource key
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDefaultMappings(string $registerId): array {
		$settings = $this->settingsService->getSettings();
		$mappings = [];
		foreach (DefaultMappingTables::BY_RESOURCE as $resourceKey => $entry) {
			$mappings[$resourceKey] = $entry['mapping'];
			if ($entry['schemaSetting'] !== null) {
				$mappings[$resourceKey] = [
					'zgwResource' => $entry['mapping']['zgwResource'],
					'zgwApiVersion' => '1',
					'sourceRegister' => $registerId,
					'sourceSchema' => ($settings[$entry['schemaSetting']] ?? ''),
					'enabled' => true,
					'propertyMapping' => (DefaultMappingTables::RECORD_IDENTITY + $entry['mapping']['propertyMapping']),
				] + $entry['mapping'];
			}
		}

		return $mappings;
	}//end getDefaultMappings()

	/**
	 * Create default test applicaties via OpenRegister's ConsumerMapper.
	 *
	 * Creates a superuser applicatie for dev/testing and a limited-scope
	 * applicatie for testing scope enforcement.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @psalm-suppress UnusedParam
	 */
	private function createDefaultApplicaties(IOutput $output): void {
		try {
			$container = \OC::$server;
			$consumerMapper = $container->get('OCA\OpenRegister\Db\ConsumerMapper');
		} catch (\Throwable $e) {
			$output->info('OpenRegister ConsumerMapper not available. Skipping default applicaties.');
			return;
		}

		$defaults = $this->getDefaultApplicaties();
		$created = 0;

		$updated = 0;

		foreach ($defaults as $applicatie) {
			$existing = $consumerMapper->findAll(filters: ['name' => $applicatie['name']]);
			if (count(value: $existing) > 0) {
				// Update existing consumer's authorization configuration
				// to ensure new scopes are applied.
				$consumer = $existing[0];
				$newConfig = $applicatie['authorizationConfiguration'] ?? [];
				$consumer->setAuthorizationConfiguration($newConfig);
				$consumer->setUpdated(new DateTime());
				$consumerMapper->update($consumer);
				$updated++;
				continue;
			}

			$applicatie['created'] = new DateTime();
			$applicatie['updated'] = new DateTime();
			$consumerMapper->createFromArray(object: $applicatie);
			$created++;
		}

		$output->info("Created {$created}, updated {$updated} default test applicaties.");
	}//end createDefaultApplicaties()

	/**
	 * Get default test applicatie configurations.
	 *
	 * @return array[] The default applicatie data
	 */
	private function getDefaultApplicaties(): array {
		return [
			[
				// FROZEN client ids + secrets below. These are ZGW consumer
				// credentials registered in the ZGW consumer table and referenced
				// by name from tests/zgw/seed-consumers.sh and the Postman
				// environments. A renamed id here makes the seed script look up a
				// consumer that does not exist — a KeyError that aborts the whole
				// e2e job before a single test runs. `procest-admin` is also a
				// Nextcloud GROUP id (see TransitionAuthorizer::ADMIN_GROUP_ID).
				'name' => 'procest-admin',
				'description' => 'Dossiq Admin (development)',
				'authorizationType' => 'jwt-zgw',
				'userId' => 'admin',
				'authorizationConfiguration' => [
					'publicKey' => 'procest-admin-secret-key-for-testing',
					'algorithm' => 'HS256',
					'superuser' => true,
					'scopes' => [],
				],
			],
			[
				'name' => 'procest-limited',
				'description' => 'Dossiq Limited (testing)',
				'authorizationType' => 'jwt-zgw',
				'userId' => 'admin',
				'authorizationConfiguration' => [
					'publicKey' => 'procest-limited-secret-key-for-test',
					'algorithm' => 'HS256',
					'superuser' => false,
					'scopes' => [
						[
							'component' => 'ztc',
							'scopes' => [
								'zaaktypen.lezen',
							],
						],
						[
							'component' => 'zrc',
							'scopes' => [
								'zaken.lezen',
							],
							'maxVertrouwelijkheidaanduiding' => 'openbaar',
						],
					],
				],
			],
		];
	}//end getDefaultApplicaties()

	/**
	 * Create default notification channels (kanalen).
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function createDefaultKanalen(IOutput $output): void {
		$channelMapping = $this->zgwMappingService->getMapping(
			resourceKey: 'kanaal'
		);
		if ($this->kanaalMappingReady(output: $output, channelMapping: $channelMapping) === false) {
			return;
		}

		$objectService = $this->kanaalObjectService(output: $output);
		if ($objectService === null) {
			return;
		}

		$result = $this->createMissingKanalen(objectService: $objectService, channelMapping: $channelMapping);

		$this->reportKanalen(output: $output, created: $result['created'], refused: $result['refused']);
	}//end createDefaultKanalen()

	/**
	 * Whether the kanaal mapping is configured with a register and schema.
	 *
	 * @param IOutput                   $output         The output interface for progress reporting
	 * @param array<string, mixed>|null $channelMapping The kanaal mapping, or null when absent
	 *
	 * @return bool True when the default channels can be written
	 */
	private function kanaalMappingReady(IOutput $output, ?array $channelMapping): bool {
		if ($channelMapping === null) {
			$output->info('Kanaal mapping not configured. Skipping default channels.');
			return false;
		}

		// On a fresh install the schema settings can still be empty when this
		// step runs; a search against an empty schema context throws and would
		// abort the install. Skip by name instead.
		if ((string)($channelMapping['sourceRegister'] ?? '') === ''
			|| (string)($channelMapping['sourceSchema'] ?? '') === ''
		) {
			$output->info('Kanaal mapping has no register/schema configured yet. Skipping default channels.');
			return false;
		}

		return true;
	}//end kanaalMappingReady()

	/**
	 * Resolve OpenRegister's ObjectService for the default channels.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return object|null The object service, or null when it is unavailable
	 */
	private function kanaalObjectService(IOutput $output): ?object {
		try {
			$container = \OC::$server;
			return $container->get(
				'OCA\OpenRegister\Service\ObjectService'
			);
		} catch (\Throwable $e) {
			$output->info('OpenRegister ObjectService not available. Skipping default channels.');
			return null;
		}
	}//end kanaalObjectService()

	/**
	 * Create every missing default channel under the system identity.
	 *
	 * @param object               $objectService  OpenRegister's ObjectService
	 * @param array<string, mixed> $channelMapping The kanaal mapping
	 *
	 * @return array{created: int, refused: list<string>} The count created and the names refused
	 */
	private function createMissingKanalen(object $objectService, array $channelMapping): array {
		$defaults = $this->getDefaultKanalen();
		$created = 0;
		$refused = [];

		// A REPAIR STEP HAS NO SESSION, SO OPENREGISTER SEES 'Anonymous'.
		// This loop is what the acceptance proof caught: every fresh install
		// logged `User 'Anonymous' does not have permission to 'create' objects
		// in schema 'Notification Channel'`, the FIRST channel threw, run()'s
		// try/catch turned it into one warning, and the remaining three were
		// never attempted. So the whole ZGW notification surface shipped with
		// no channels while the step's own line never printed a zero.
		//
		// The elevation is the same one every other writing step already uses;
		// the per-row try is the other half, so one refused channel no longer
		// takes the other three with it and the count names what is missing.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $channelMapping, $defaults, &$created, &$refused): void {
				foreach ($defaults as $channel) {
					$outcome = $this->createKanaal(
						objectService: $objectService,
						channelMapping: $channelMapping,
						channel: $channel
					);
					if ($outcome === 'created') {
						$created++;
					} else if ($outcome === 'refused') {
						$refused[] = (string)$channel['name'];
					}
				}
			}
		);

		return ['created' => $created, 'refused' => $refused];
	}//end createMissingKanalen()

	/**
	 * Create one default channel unless it already exists.
	 *
	 * @param object               $objectService  OpenRegister's ObjectService
	 * @param array<string, mixed> $channelMapping The kanaal mapping
	 * @param array<string, mixed> $channel        The default channel data
	 *
	 * @return string 'created', 'exists' or 'refused'
	 */
	private function createKanaal(object $objectService, array $channelMapping, array $channel): string {
		try {
			// Check if kanaal already exists.
			$query = $objectService->buildSearchQuery(
				requestParams: ['name' => $channel['name']],
				register: $channelMapping['sourceRegister'],
				schema: $channelMapping['sourceSchema']
			);
			$existing = $objectService->searchObjectsPaginated(query: $query);
			if (($existing['total'] ?? 0) > 0) {
				return 'exists';
			}

			$objectService->saveObject(
				register: $channelMapping['sourceRegister'],
				schema: $channelMapping['sourceSchema'],
				object: $channel
			);
			return 'created';
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: default notification channel refused',
				['channel' => $channel['name'], 'exception' => $e->getMessage()]
			);
			return 'refused';
		}//end try
	}//end createKanaal()

	/**
	 * Report how many default channels were created and which were refused.
	 *
	 * @param IOutput      $output  The output interface for progress reporting
	 * @param int          $created Number of channels created
	 * @param list<string> $refused Names of refused channels
	 *
	 * @return void
	 */
	private function reportKanalen(IOutput $output, int $created, array $refused): void {
		if ($refused !== []) {
			$output->warning(
				sprintf(
					'Created %d default notification channels; %d REFUSED (%s). ZGW notifications on those channels cannot be delivered.',
					$created,
					count($refused),
					implode(', ', $refused)
				)
			);
			return;
		}

		$output->info("Created {$created} default notification channels.");
	}//end reportKanalen()

	/**
	 * Get default notification channel configurations.
	 *
	 * @return array[] The default kanaal data
	 */
	private function getDefaultKanalen(): array {
		return [
			[
				'name' => 'zaken',
				'filters' => [
					'bronorganisatie',
					'caseType',
					'vertrouwelijkheidaanduiding',
				],
			],
			[
				'name' => 'documenten',
				'filters' => [
					'bronorganisatie',
					'informatieobjecttype',
					'vertrouwelijkheidaanduiding',
				],
			],
			[
				'name' => 'besluiten',
				'filters' => [
					'verantwoordelijkeOrganisatie',
					'besluittype',
				],
			],
			[
				'name' => 'catalogi',
				'filters' => [],
			],
			[
				'name' => 'autorisaties',
				'filters' => [],
			],
		];
	}//end getDefaultKanalen()

}//end class
