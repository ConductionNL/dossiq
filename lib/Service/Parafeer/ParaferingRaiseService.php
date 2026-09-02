<?php

/**
 * Dossiq parafering raise.
 *
 * The whole of what this app still does when a voorstel enters parafering:
 * resolve the route locally, refuse a voorstel that cannot be routed, hand the
 * route AND the subject to the decision app, and record on the voorstel what
 * was asked. The decision app runs the chain — sequential and parallel steps,
 * mandated delegates, terugsturen — and its conclusion event is the next thing
 * this app hears ({@see \OCA\Dossiq\Listener\ParaferingConcludedListener}).
 *
 * This REPLACES BesluitvormingParafeerService, which resolved the route,
 * advanced `currentStep` over a `routeSnapshot`, and closed the chain locally.
 * dossiq delegates parafering exactly like it delegates decisions now: raise,
 * wait for the conclusion, keep the case record. No facade stands in for the
 * old service — the approval-consolidation precedent — so a caller that still
 * wants local advancement fails at compile time rather than silently running
 * a second engine.
 *
 * 🔴 THE RAISE FAILS CLOSED. The old activation treated the decision app as
 * additional; with no local runtime left, "additional" would park a voorstel
 * in `in_parafering` with no engine anywhere to move it — the exact stranding
 * the empty-snapshot refusal exists to prevent, one seam further out. An
 * install without the decision app keeps its voorstellen OUT of parafering
 * and says why, loudly, like Bezwaar/DecisionService::publish() does for
 * decisions.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raises a voorstel's parafering at the decision app.
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */
class ParaferingRaiseService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService             $settingsService Register/schema configuration.
	 * @param ParafeerrouteDirectory      $routes          Resolves the sign-off route for a case type.
	 * @param ParaferingDelegationService $delegation      Holds the route and starts the chain in the decision app.
	 * @param ObjectArrayNormalizer       $normalizer      Collapses OpenRegister's array-or-entity shape.
	 * @param LoggerInterface             $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ParafeerrouteDirectory $routes,
		private readonly ParaferingDelegationService $delegation,
		private readonly ObjectArrayNormalizer $normalizer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Put a voorstel into parafering by raising its chain at the decision app.
	 *
	 * The snapshot is still written, as a RECORD of what was asked — the frozen
	 * copy the case file keeps — not as runtime state: nothing in this app
	 * advances over it any more.
	 *
	 * @param string $proposalId The voorstel uuid.
	 *
	 * @return array<string, mixed> The updated voorstel.
	 *
	 * @throws RuntimeException When the voorstel cannot be routed, or the
	 *         decision app is absent or refuses — the raise fails closed.
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
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

		$proposal = $this->loadVoorstel(
			objectService: $objectService,
			register: $register,
			proposalSchema: $proposalSchema,
			proposalId: $proposalId,
		);

		$caseTypeId = (string)($proposal['caseType'] ?? '');
		$route = $this->routes->localRoute(caseTypeId: $caseTypeId);
		$routeSnapshot = [];
		if ($route !== null) {
			$routeSnapshot = $this->routes->stepsForCaseType(caseTypeId: $caseTypeId);
		}

		// 🔴 REFUSED, not carried on with an empty snapshot. A voorstel that
		// cannot be routed is not put into parafering (REQ-PTD-003 stands).
		if ($route === null || $routeSnapshot === []) {
			throw new RuntimeException(
				'No parafeerroute is configured for this case type, so the voorstel cannot enter parafering: ' . $proposalId
			);
		}

		// 🔴 FAIL CLOSED. holdRoute throws when the decision app is absent,
		// does not handle the command, or answers no route id. There is no
		// local chain to fall back to, and a voorstel parked in parafering
		// with no engine anywhere is the one outcome worse than a refusal.
		$approvalRouteId = $this->delegation->holdRoute(
			route: $route,
			actorId: '',
			subject: $proposalId,
			subjectSchema: $proposalSchema,
		);

		$updateData = [
			'status' => 'in_parafering',
			'currentStep' => 1,
			'routeSnapshot' => $routeSnapshot,
			'approvalRouteId' => $approvalRouteId,
		];

		$updated = $objectService->saveObject(
			object: array_merge($proposal, $updateData),
			register: $register,
			schema: $proposalSchema
		);

		$this->logger->info(
			'Dossiq parafering raised at the decision app for proposal: ' . $proposalId,
			['app' => Application::APP_ID, 'approvalRouteId' => $approvalRouteId]
		);

		return $this->normalizer->toArray(value: $updated);
	}//end activate()

	/**
	 * Load the voorstel, refusing the unknown.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $proposalSchema The voorstel schema identifier.
	 * @param string $proposalId The voorstel uuid.
	 *
	 * @return array<string, mixed> The voorstel.
	 *
	 * @throws RuntimeException When the voorstel is not found.
	 */
	private function loadVoorstel(
		object $objectService,
		string $register,
		string $proposalSchema,
		string $proposalId,
	): array {
		$results = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $proposalSchema,
			filters: ['id' => $proposalId]
		);

		if (empty($results) === true) {
			throw new RuntimeException('Voorstel not found: ' . $proposalId);
		}

		return $this->normalizer->toArray(value: $results[0]);
	}//end loadVoorstel()

}//end class
