<?php

/**
 * Dossiq BesluitvormingParafeerService
 *
 * Service for orchestrating the parafering chain within the besluitvorming
 * workflow. Activates a parafeerroute for a voorstel, handles individual
 * paraaf actions, and checks chain completion.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\Parafeer\ParaferingFlowGateway;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the parafering chain for besluitvorming voorstellen.
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
 */
class BesluitvormingParafeerService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService             $settingsService Settings service for register and schema references.
	 * @param ParafeerrouteDirectory      $routes          Resolves the sign-off route for a case type.
	 * @param ParaferingDelegationService $delegation      Holds the route in the decision app.
	 * @param ParaferingFlowGateway       $flows           Starts the projected flow, when it is enabled.
	 * @param LoggerInterface             $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ParafeerrouteDirectory $routes,
		private readonly ParaferingDelegationService $delegation,
		private readonly ParaferingFlowGateway $flows,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Activate the parafering chain for a voorstel.
	 *
	 * Loads the voorstel from OpenRegister, finds the appropriate parafeerroute,
	 * creates a route snapshot, sets currentStep to 1, creates a task for the
	 * first parafeerder, and updates the voorstel status to 'in_parafering'.
	 *
	 * @param string $proposalId The UUID of the voorstel.
	 *
	 * @return array<string, mixed> The updated voorstel.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or the voorstel is not found.
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
	 */
	public function activate(string $proposalId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$proposalSchema = $this->settingsService->getConfigValue('voorstel_schema');

		if (empty($register) === true || empty($proposalSchema) === true) {
			throw new RuntimeException('Dossiq register or voorstel_schema not configured');
		}

		// Load the voorstel.
		$proposalResults = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $proposalSchema,
			filters: ['id' => $proposalId]
		);

		if (empty($proposalResults) === true) {
			throw new RuntimeException('Voorstel not found: ' . $proposalId);
		}

		$proposal = $this->toArray(value: $proposalResults[0]);

		$caseTypeId = (string)($proposal['caseType'] ?? '');
		$route = $this->routes->localRoute(caseTypeId: $caseTypeId);
		$routeSnapshot = [];
		if ($route !== null) {
			$routeSnapshot = $this->routes->stepsForCaseType(caseTypeId: $caseTypeId);
		}

		// 🔴 REFUSED, not carried on with an empty snapshot. Activating without
		// steps wrote `currentStep: 1, status: in_parafering, routeSnapshot: []`,
		// and every action after it then failed `Current step not found in route
		// snapshot` — a message about the snapshot when the fault is a route
		// nobody configured. The voorstel was parked in parafering with no way
		// forward and no way back. A voorstel that cannot be routed is not put
		// into parafering.
		if ($routeSnapshot === []) {
			throw new RuntimeException(
				'No parafeerroute is configured for this case type, so the voorstel cannot enter parafering: ' . $proposalId
			);
		}

		// Update the voorstel with route snapshot and initial step.
		$updateData = [
			'currentStep' => 1,
			'status' => 'in_parafering',
			'routeSnapshot' => $routeSnapshot,
		];

		// Hold the route in the decision app and start the sign-off chain there.
		// ADDITIONAL, never required: the decision app is an optional runtime
		// dependency and a voorstel must still enter parafering without it. What
		// it resolves to is written onto the voorstel rather than only logged, so
		// an unmirrored voorstel is something you can query for.
		$approvalRouteId = $this->holdRouteInDecisionApp(
			route: $route,
			proposalId: $proposalId,
			proposalSchema: $proposalSchema,
		);
		if ($approvalRouteId !== '') {
			$updateData['approvalRouteId'] = $approvalRouteId;
		}

		// 🔴 THE DUAL PATH.
		//
		// A route whose projected flow is ENABLED drives parafering through the
		// engine, and the voorstel records which run. Every other voorstel gets
		// no run id and finishes on the route snapshot above, which is what
		// keeps anything already mid-parafering from being stranded: a hard
		// cutover would leave those voorstellen waiting on a run nobody started.
		//
		// The projections ship disabled, so today this is inert. Enabling one
		// flow is what moves one route onto the engine, and this is the line
		// that reads that decision.
		$flowRunId = $this->flows->startForRoute(
			routeId: (string)($route['id'] ?? ''),
			subjectId: $proposalId,
			context: ['caseType' => $caseTypeId],
		);
		if ($flowRunId !== '') {
			$updateData['flowRunId'] = $flowRunId;
		}

		$updated = $objectService->saveObject(object: array_merge($proposal, $updateData), register: $register, schema: $proposalSchema);

		$this->logger->info(
			'Besluitvorming parafering activated for proposal: ' . $proposalId,
			['app' => Application::APP_ID, 'flowRun' => $flowRunId]
		);

		return $this->toArray(value: $updated);
	}//end activate()

	/**
	 * Ask the decision app to hold this route and start the chain, best effort.
	 *
	 * Returns an empty string when the decision app is absent or refuses. The
	 * caller writes the result onto the voorstel, so "which voorstellen were
	 * mirrored" stays a query rather than an archaeology exercise over the log.
	 *
	 * @param array<string, mixed> $route          The local parafeerroute.
	 * @param string               $proposalId     The voorstel uuid.
	 * @param string               $proposalSchema The voorstel schema slug.
	 *
	 * @return string The approval-route id, or an empty string.
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	private function holdRouteInDecisionApp(array $route, string $proposalId, string $proposalSchema): string {
		if ($this->delegation->isAvailable() === false) {
			return '';
		}

		try {
			return $this->delegation->holdRoute(
				route: $route,
				actorId: '',
				subject: $proposalId,
				subjectSchema: $proposalSchema,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: the decision app did not hold the route; the voorstel is unmirrored',
				[
					'app' => Application::APP_ID,
					'proposal' => $proposalId,
					'error' => $e->getMessage(),
				]
			);

			return '';
		}

	}//end holdRouteInDecisionApp()

	/**
	 * Handle a paraaf action for a voorstel.
	 *
	 * Loads the parafeeractie, advances to next step on 'approved', or
	 * sets status 'retour' on 'retour'. When all steps are complete, transitions
	 * the parent case to 'Gereed voor agendering'.
	 *
	 * @param string $proposalId The UUID of the voorstel.
	 * @param string $parafeeractieId The UUID of the parafeeractie.
	 *
	 * @return array<string, mixed> The updated voorstel.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or objects are not found.
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
	 */
	public function handleParaafAction(string $proposalId, string $parafeeractieId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$proposalSchema = $this->settingsService->getConfigValue('voorstel_schema');
		$actionSchema = $this->settingsService->getConfigValue('parafeeractie_schema');

		if (empty($register) === true || empty($proposalSchema) === true) {
			throw new RuntimeException('Dossiq register or voorstel_schema not configured');
		}

		// Load voorstel.
		$proposalResults = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $proposalSchema,
			filters: ['id' => $proposalId]
		);

		if (empty($proposalResults) === true) {
			throw new RuntimeException('Voorstel not found: ' . $proposalId);
		}

		$proposal = $this->toArray(value: $proposalResults[0]);

		if ($this->flowDrivesThis(proposal: $proposal, proposalId: $proposalId) === true) {
			return $proposal;
		}

		// Load parafeeractie.
		$action = $this->resolveParaafActionType(
			objectService: $objectService,
			register: $register,
			actionSchema: $actionSchema,
			parafeeractieId: $parafeeractieId,
		);

		// 🔴 'returned', not 'retour', and 'teruggestuurd', not 'retour'.
		//
		// A parafeeractie's `action` is a closed enum: parafered, returned,
		// advised, skipped, accorded. 'retour' is not in it, so this branch
		// could never be reached by a paraaf that passed validation — and an
		// approver who sent a voorstel back fell through to the advance below,
		// moving it FORWARD to the next approver. A rejection read as an
		// approval.
		//
		// The status it then wrote was equally unreachable: the voorstel status
		// enum has no 'retour' either. openspec/specs/parafering-actions
		// spells both out — action 'returned' sets status 'teruggestuurd' —
		// and src/utils/parafeerEngine.js has had it right all along.
		if ($action === 'returned') {
			$updated = $objectService->saveObject(
				object: array_merge($proposal, ['status' => 'teruggestuurd']),
				register: $register,
				schema: $proposalSchema
			);
			return $this->toArray(value: $updated);
		}

		// Advance to next step.
		$nextStep = $this->findNextParaafStep(
			snapshot: ($proposal['routeSnapshot'] ?? []),
			currentStep: (int)($proposal['currentStep'] ?? 1),
		);

		if ($nextStep === null) {
			// 'geaccordeerd', the enum's own word for it. 'gereed_voor_agendering'
			// is not a voorstel status and never was, so the moment every paraaf
			// was collected wrote a value the schema rejects and the UI cannot
			// render. getStatusAfterAdvance() in src/utils/parafeerEngine.js
			// returns 'geaccordeerd' for exactly this transition.
			$updateData = ['status' => 'geaccordeerd', 'currentStep' => 0];
			$updated = $objectService->saveObject(
				object: array_merge($proposal, $updateData),
				register: $register,
				schema: $proposalSchema
			);

			$this->logger->info(
				'All parafen collected for proposal: ' . $proposalId . ', transitioning case.',
				['app' => Application::APP_ID]
			);

			return $this->toArray(value: $updated);
		}

		// Advance to next step.
		$updateData = ['currentStep' => $nextStep, 'status' => 'in_parafering'];
		$updated = $objectService->saveObject(
			object: array_merge($proposal, $updateData),
			register: $register,
			schema: $proposalSchema
		);

		return $this->toArray(value: $updated);
	}//end handleParaafAction()

	/**
	 * 🔴 THE FLOW DRIVES, OR THIS DOES. NEVER BOTH.
	 *
	 * A voorstel carrying a flow run is advanced by that run: the paraaf the
	 * approver just gave is picked up by ParaafResumeListener, which signals
	 * the run, and the run's own nodes ask the next approver and write the
	 * final status. Advancing the route snapshot here as well would ask every
	 * approver twice and race the flow to the status.
	 *
	 * Every other voorstel — all of them until an operator enables a route's
	 * projected flow, and all of the ones already mid-parafering afterwards —
	 * finishes exactly as it started, on the snapshot.
	 *
	 * @param array<string, mixed> $proposal   The voorstel.
	 * @param string               $proposalId Its id, for the log line.
	 *
	 * @return boolean True when the flow owns this voorstel.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function flowDrivesThis(array $proposal, string $proposalId): bool {
		$runId = trim((string)($proposal['flowRunId'] ?? ''));
		if ($runId === '') {
			return false;
		}

		$this->logger->info(
			'Besluitvorming: voorstel ' . $proposalId . ' is driven by a flow run, so the route snapshot stands aside',
			['app' => Application::APP_ID, 'flowRun' => $runId]
		);

		return true;

	}//end flowDrivesThis()

	/**
	 * Resolve the action recorded on a parafeeractie, defaulting to approval.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $actionSchema The parafeeractie schema identifier, may be empty.
	 * @param string $parafeeractieId The UUID of the parafeeractie.
	 *
	 * @return string The action slug ('approved' when unresolvable).
	 */
	private function resolveParaafActionType(
		object $objectService,
		string $register,
		string $actionSchema,
		string $parafeeractieId,
	): string {
		if (empty($actionSchema) === true) {
			return 'parafered';
		}

		$actionResults = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $actionSchema,
			filters: ['id' => $parafeeractieId]
		);

		if (empty($actionResults) === true) {
			return 'parafered';
		}

		$action = $this->toArray(value: $actionResults[0]);

		return (string)($action['action'] ?? 'parafered');
	}//end resolveParaafActionType()

	/**
	 * Find the lowest route-snapshot step order beyond the current step.
	 *
	 * @param mixed $snapshot The route snapshot (array or JSON string).
	 * @param int $currentStep The step the voorstel is currently on.
	 *
	 * @return int|null The next step order, or null when all steps are done.
	 */
	private function findNextParaafStep(mixed $snapshot, int $currentStep): ?int {
		if (is_string($snapshot) === true) {
			$snapshot = json_decode($snapshot, true) ?? [];
		}

		$nextStep = null;
		foreach ($snapshot as $step) {
			if (is_array($step) === false) {
				continue;
			}

			$stepOrder = (int)($step['order'] ?? 0);
			if ($stepOrder <= $currentStep) {
				continue;
			}

			if ($nextStep === null || $stepOrder < $nextStep) {
				$nextStep = $stepOrder;
			}
		}//end foreach

		return $nextStep;
	}//end findNextParaafStep()

	/**
	 * Check whether all required parafen have been collected for a voorstel.
	 *
	 * Queries all parafeeracties for the voorstel and checks whether every
	 * required step has action='approved'.
	 *
	 * @param string $proposalId The UUID of the voorstel.
	 *
	 * @return bool True when all required parafen are collected, false otherwise.
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
	 */
	public function allParafenCollected(string $proposalId): bool {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$actionSchema = $this->settingsService->getConfigValue('parafeeractie_schema');

		if (empty($register) === true || empty($actionSchema) === true) {
			return false;
		}

		try {
			$acties = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $actionSchema,
				filters: ['proposal' => $proposalId]
			);

			if (empty($acties) === true) {
				return false;
			}

			foreach ($acties as $action) {
				$actionArr = $this->toArray(value: $action);
				if ((string)($actionArr['action'] ?? '') !== 'approved') {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BesluitvormingParafeerService::allParafenCollected failed',
				['voorstelId' => $proposalId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end allParafenCollected()

	/**
	 * Normalize an ObjectService return value to an array.
	 *
	 * @param mixed $value The value to normalize.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray($value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				$serialized = $value->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($value, 'toArray') === true) {
				$converted = $value->toArray();
				if (is_array($converted) === true) {
					return $converted;
				}
			}
		}

		return [];
	}//end toArray()
}//end class
