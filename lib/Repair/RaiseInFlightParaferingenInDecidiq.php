<?php

/**
 * Dossiq Raise In-Flight Paraferingen In Decidiq Repair.
 *
 * The retirement of the local parafering runtime removes the engine that
 * advanced a voorstel already mid-parafering. A hard cutover would strand every
 * such voorstel — `in_parafering` or `ter_accordering`, with a `routeSnapshot`
 * and a `currentStep`, and nothing left in this app to move it. This step hands
 * each one to the decision app so the engine that now owns parafering has a
 * chain to finish.
 *
 * 🔴 THE CHAIN RESUMES FROM THE START IN THE DECISION APP, and that is stated
 * rather than hidden. The decision app instantiates a route's stages from step
 * one; it cannot import a partial local history the local runtime never sent.
 * A voorstel three signatures into a five-step route will be asked for all five
 * again in the new engine. This is the honest cost of a runtime move done after
 * voorstellen were already travelling, and it is why the step logs each voorstel
 * it re-raises. The dev instance holds zero voorstellen so it cannot surface
 * this; production can, which is exactly why the re-raise is explicit and
 * counted rather than silent.
 *
 * Idempotent: the decision app resolves the subject before instantiating and
 * leaves a subject that already has stages alone, so a re-run re-raises
 * nothing. A voorstel whose route was never held (no `approvalRouteId`) is
 * skipped and named — there is no route in the decision app to travel.
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
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Re-raises each in-flight voorstel's chain in the decision app.
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */
class RaiseInFlightParaferingenInDecidiq implements IRepairStep {

	use SearchesObjects;

	/**
	 * Voorstellen read per pass.
	 *
	 * @var integer
	 */
	private const BATCH_LIMIT = 500;

	/**
	 * The statuses that mean a voorstel is still travelling its route.
	 *
	 * @var array<int, string>
	 */
	private const IN_FLIGHT_STATUSES = ['in_parafering', 'ter_accordering'];

	/**
	 * Constructor.
	 *
	 * @param ParaferingDelegationService $delegation Holds the route and starts the chain.
	 * @param ParafeerrouteDirectory      $routes     Resolves a voorstel's route for the re-raise.
	 * @param SettingsService             $settings   Resolves OpenRegister and the schema slugs.
	 * @param LoggerInterface             $logger     Logger.
	 */
	public function __construct(
		private readonly ParaferingDelegationService $delegation,
		private readonly ParafeerrouteDirectory $routes,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name shown during the upgrade.
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
	 */
	public function getName(): string {
		return 'Dossiq: re-raise in-flight paraferingen in the decision app';

	}//end getName()

	/**
	 * Re-raise every voorstel still in parafering.
	 *
	 * @param IOutput $output The migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->delegation->isAvailable() === false) {
			// The runtime lives in the decision app now, so without it there is
			// nowhere to raise a chain. Reported as a skip, not a success: an
			// install in this state has stranded voorstellen and an operator
			// needs to know, but a missing optional app must not fail an upgrade.
			$output->warning(
				'Dossiq: the decision app is not installed; in-flight paraferingen could not be re-raised and have no engine to finish on.'
			);
			return;
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			$output->warning('Dossiq: OpenRegister is not available; in-flight paraferingen were not re-raised.');
			return;
		}

		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'voorstel_schema');
		if ($register === '' || $schema === '') {
			$output->warning('Dossiq: the voorstel register/schema is not configured; nothing was re-raised.');
			return;
		}

		// A repair step has no session; without a system identity every write
		// is refused as Anonymous while the upgrade still reports success. The
		// migration that establishes no identity must fail loudly, not no-op.
		if (method_exists($objectService, 'runAsSystem') === false) {
			throw new RuntimeException(
				'Dossiq: OpenRegister exposes no runAsSystem(); the in-flight parafering migration cannot establish an identity and refuses to run as Anonymous.'
			);
		}

		$counts = ['raised' => 0, 'skipped' => 0, 'failed' => 0];

		$objectService->runAsSystem(
			function () use ($objectService, $register, $schema, $output, &$counts): void {
				foreach ($this->readInFlight(objectService: $objectService, register: $register, schema: $schema, output: $output) as $voorstel) {
					$this->raiseOne(
						schema: $schema,
						voorstel: $voorstel,
						output: $output,
						counts: $counts,
					);
				}
			}
		);

		$output->info(
			sprintf(
				'Dossiq in-flight paraferingen: %d re-raised, %d skipped, %d failed.',
				$counts['raised'],
				$counts['skipped'],
				$counts['failed']
			)
		);

	}//end run()

	/**
	 * Read the voorstellen still in parafering.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register slug.
	 * @param string  $schema        The voorstel schema slug.
	 * @param IOutput $output        The migration output.
	 *
	 * @return array<int, array<string, mixed>> The in-flight voorstellen.
	 */
	private function readInFlight(object $objectService, string $register, string $schema, IOutput $output): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::BATCH_LIMIT],
			);
		} catch (Throwable $e) {
			$output->warning('Dossiq: could not list voorstellen: ' . $e->getMessage());

			return [];
		}

		$inFlight = [];
		foreach ($rows as $row) {
			if (in_array((string)($row['status'] ?? ''), self::IN_FLIGHT_STATUSES, true) === true) {
				$inFlight[] = $row;
			}
		}

		return $inFlight;

	}//end readInFlight()

	/**
	 * Re-raise one voorstel, or account for why it was not.
	 *
	 * @param string               $schema   The voorstel schema slug.
	 * @param array<string, mixed> $voorstel The voorstel row.
	 * @param IOutput              $output   The migration output.
	 * @param array<string, int>   $counts   Running tallies, by reference.
	 *
	 * @return void
	 */
	private function raiseOne(string $schema, array $voorstel, IOutput $output, array &$counts): void {
		$id = (string)($voorstel['id'] ?? ($voorstel['@self']['id'] ?? ''));
		if ($id === '') {
			$counts['failed']++;
			return;
		}

		// The voorstel schema declares no caseType, so the type is derived
		// from the voorstel's linked case — a direct read is always ''.
		$route = $this->routes->localRoute(caseTypeId: $this->routes->caseTypeOfVoorstel(voorstel: $voorstel));
		if ($route === null) {
			// No route resolves for this voorstel's case type, so there is
			// nothing to travel. Named, so a stranded voorstel is visible.
			$counts['skipped']++;
			$output->warning('Dossiq: voorstel ' . $id . ' is in parafering but its case type has no route; skipped.');

			return;
		}

		try {
			$this->delegation->holdRoute(
				route: $route,
				actorId: '',
				subject: $id,
				subjectSchema: $schema,
			);
		} catch (Throwable $e) {
			$counts['failed']++;
			$output->warning('Dossiq: could not re-raise voorstel ' . $id . ': ' . $e->getMessage());
			$this->logger->warning(
				'RaiseInFlightParaferingenInDecidiq: voorstel failed',
				['voorstel' => $id, 'error' => $e->getMessage()]
			);

			return;
		}

		$counts['raised']++;
		$this->logger->info(
			'Dossiq: re-raised in-flight voorstel ' . $id . ' in the decision app; its chain resumes from the start there'
		);

	}//end raiseOne()

}//end class
