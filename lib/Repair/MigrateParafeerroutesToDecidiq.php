<?php

/**
 * Dossiq Migrate Parafeerroutes To Decidiq Repair
 *
 * Migration repair step: for each local `parafeerroute`, cause an ApprovalRoute
 * to exist in the decision app and record its id back on the local row.
 * Routing a document past a sequence of officials is governance, and governance
 * is the decision app's.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Holds each local parafeerroute in the decision app and records the mapping.
 *
 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
 */
class MigrateParafeerroutesToDecidiq implements IRepairStep {

	use SearchesObjects;

	/**
	 * Routes read per pass.
	 *
	 * @var integer
	 */
	private const BATCH_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ParaferingDelegationService $delegation Holds the route in the decision app.
	 * @param SettingsService             $settings   Resolves OpenRegister and the schema slugs.
	 * @param LoggerInterface             $logger     Logger.
	 */
	public function __construct(
		private readonly ParaferingDelegationService $delegation,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name shown during the upgrade.
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function getName(): string {
		return 'Dossiq: hold parafeerroutes as approval routes';

	}//end getName()

	/**
	 * Hold every route that has not been held yet.
	 *
	 * @param IOutput $output The migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->delegation->isAvailable() === false) {
			// The decision app is an OPTIONAL runtime dependency. An install
			// without it keeps its local routes and keeps working; this is the
			// one case where skipping is correct rather than a silent no-op, so
			// it is reported as a skip and not as a success.
			$output->info('Dossiq: the decision app is not installed; parafeerroutes stay local.');
			return;
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			$output->warning('Dossiq: OpenRegister is not available; parafeerroutes were not held.');
			return;
		}

		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'parafeerroute_schema');
		if ($register === '' || $schema === '') {
			$output->warning('Dossiq: the parafeerroute register/schema is not configured; nothing was held.');
			return;
		}

		// 🔴 A repair step runs during `occ upgrade`, where there is NO session.
		// Without a system identity OpenRegister resolves the actor as Anonymous
		// and refuses every write — and $output->warning() does not fail an
		// upgrade, so the migration would do nothing while the upgrade reported
		// success. The shared trait falls back to running the operation bare
		// when runAsSystem() is absent; here that fallback is exactly that
		// silent no-op, so this step FAILS instead.
		if (method_exists($objectService, 'runAsSystem') === false) {
			throw new RuntimeException(
				'Dossiq: OpenRegister exposes no runAsSystem(); the parafeerroute migration cannot establish an identity and refuses to run as Anonymous.'
			);
		}

		$counts = ['held' => 0, 'skipped' => 0, 'failed' => 0];

		$objectService->runAsSystem(
			function () use ($objectService, $register, $schema, $output, &$counts): void {
				$routes = $this->readRoutes(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					output: $output,
				);

				foreach ($routes as $route) {
					$this->migrateOne(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						route: $route,
						output: $output,
						counts: $counts,
					);
				}
			}
		);

		$output->info(
			sprintf(
				'Dossiq parafeerroutes: %d held, %d already mapped, %d failed.',
				$counts['held'],
				$counts['skipped'],
				$counts['failed']
			)
		);

	}//end run()

	/**
	 * Read the local routes.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register slug.
	 * @param string  $schema        The route schema slug.
	 * @param IOutput $output        The migration output.
	 *
	 * @return array<int, array<string, mixed>> The route rows.
	 */
	private function readRoutes(object $objectService, string $register, string $schema, IOutput $output): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::BATCH_LIMIT],
			);
		} catch (Throwable $e) {
			$output->warning('Dossiq: could not list parafeerroutes: ' . $e->getMessage());
			$this->logger->warning(
				'MigrateParafeerroutesToDecidiq: list failed',
				['error' => $e->getMessage()]
			);

			return [];
		}

	}//end readRoutes()

	/**
	 * Hold one route, or account for why it was not held.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register slug.
	 * @param string               $schema        The route schema slug.
	 * @param array<string, mixed> $route         The route row.
	 * @param IOutput              $output        The migration output.
	 * @param array<string, int>   $counts        Running tallies, by reference.
	 *
	 * @return void
	 */
	private function migrateOne(
		object $objectService,
		string $register,
		string $schema,
		array $route,
		IOutput $output,
		array &$counts,
	): void {
		$id = (string)($route['id'] ?? ($route['@self']['id'] ?? ''));
		if ($id === '') {
			$counts['failed']++;
			return;
		}

		if (trim((string)($route['approvalRouteId'] ?? '')) !== '') {
			$counts['skipped']++;
			return;
		}

		try {
			$routeId = $this->delegation->holdRoute(route: $route);
		} catch (Throwable $e) {
			// One route that cannot be held must not abandon the rest. It is
			// counted and named, so the summary reports a partial run as partial.
			$counts['failed']++;
			$output->warning('Dossiq: could not hold parafeerroute ' . $id . ': ' . $e->getMessage());
			$this->logger->warning(
				'MigrateParafeerroutesToDecidiq: route failed',
				['route' => $id, 'error' => $e->getMessage()]
			);

			return;
		}

		try {
			$objectService->saveObject(
				object: ($route + ['approvalRouteId' => $routeId]),
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			// The route is held; only the local note failed. Counted as failed
			// so the summary is honest, and harmless to retry: the other side
			// resolves on (sourceApp, externalReference) and matches the route
			// it already has rather than holding a second.
			$counts['failed']++;
			$output->warning('Dossiq: held a route for ' . $id . ' but could not record it: ' . $e->getMessage());

			return;
		}

		$counts['held']++;

	}//end migrateOne()

}//end class
