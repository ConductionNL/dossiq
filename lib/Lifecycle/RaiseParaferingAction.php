<?php

/**
 * Dossiq raise-parafering lifecycle action.
 *
 * The second door onto the parafering chain, wired to the same engine as the
 * first.
 *
 * A voorstel enters parafering in two ways on a live install, and only one of
 * them used to do anything:
 *
 *   1. THE CASE. A `workflowTemplate` transition into "Parafering" declares
 *      `automaticActions: [{type: besluitvormingActivate}]`, which dossiq#1729
 *      moved from the step onto the transition, where the engine reads it. All
 *      three shipped besluitvorming bundles carry it and are seeded active on a
 *      fresh install — measured on a clean rig, not inferred from the file.
 *
 *   2. THE VOORSTEL ITSELF. The `proposal` schema declares an OpenRegister
 *      lifecycle whose `startParafering` transition moves `draft` into
 *      `in_parafering`. It had a guard and NO action. Taking it therefore did
 *      exactly what the transition says and nothing else: the voorstel came to
 *      rest in `in_parafering` with no chain raised anywhere, no
 *      `approvalRouteId`, and no engine that would ever move it on. That is
 *      precisely the stranding {@see \OCA\Dossiq\Service\Parafeer\ParaferingRaiseService}
 *      refuses to create — reached through the door that service does not
 *      guard. `geaccordeerd` is written only by the conclusion recorder, which
 *      only ever hears from a chain that was raised, so from there the status
 *      was unreachable.
 *
 * So the fix is not to retire either artifact — both are live — but to give the
 * transition the action it was missing, and let it fail closed the way the
 * other door does. When the raise is refused the handler throws, OpenRegister
 * aborts the save, and the voorstel stays in `draft` with the reason. A
 * voorstel that cannot enter parafering must not appear to have entered it.
 *
 * 🔴 THE IDEMPOTENCY CHECK IS LOAD-BEARING, NOT DEFENSIVE. `activate()` writes
 * `status = in_parafering` itself, so the CASE path's raise is also a
 * `draft → in_parafering` transition and fires this very action. That write
 * already carries the `approvalRouteId` the raise just obtained, which is
 * exactly what tells the two apart: a payload arriving with a route id is the
 * raise recording its own result, and raising again would hold a SECOND chain
 * at the decision app for one voorstel.
 *
 * @category Lifecycle
 * @package  OCA\Dossiq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\Lifecycle;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Parafeer\ParaferingRaiseService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raises the parafering chain when a voorstel enters `in_parafering`.
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */
class RaiseParaferingAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ParaferingRaiseService $raiseService Raises the chain at the decision app.
	 * @param SettingsService $settingsService Resolves the voorstel schema identifier.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ParaferingRaiseService $raiseService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise the chain and fold the record of it into the payload being saved.
	 *
	 * @param array<string, mixed> $objectData The voorstel payload, status already moved to `in_parafering`.
	 * @param array<string, mixed> $previousData The voorstel payload before the transition.
	 * @param array<string, mixed> $parameters The declared `actionParameters` block; this action takes none.
	 * @param string $actionName The declared action name that resolved here.
	 *
	 * @return array<string, mixed> The payload, carrying the raise's record.
	 *
	 * @throws RuntimeException When the voorstel cannot be routed, or the decision app is absent or refuses.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $parameters/$actionName are mandated by the interface.
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$alreadyHeld = trim((string)($objectData['approvalRouteId'] ?? ''));
		if ($alreadyHeld !== '') {
			// The case path's own write. See the class docblock: raising again
			// would hold a second chain for one voorstel.
			$this->logger->debug(
				'Dossiq: voorstel entered parafering carrying an approvalRouteId; the chain is already held',
				['app' => Application::APP_ID, 'approvalRouteId' => $alreadyHeld]
			);

			return $objectData;
		}

		$proposalId = $this->proposalId(objectData: $objectData, previousData: $previousData);
		if ($proposalId === '') {
			// Fail loud rather than no-op: a raise that cannot name its
			// subject cannot be recorded against one either.
			throw new RuntimeException(
				'A voorstel entering parafering carries no id, so no chain can be raised for it.'
			);
		}

		$proposalSchema = trim((string)$this->settingsService->getConfigValue(key: 'voorstel_schema'));
		if ($proposalSchema === '') {
			throw new RuntimeException('Dossiq voorstel_schema is not configured, so parafering cannot be raised.');
		}

		$raised = $this->raiseService->raiseFields(
			proposal: $objectData,
			proposalId: $proposalId,
			proposalSchema: $proposalSchema
		);

		$this->logger->info(
			'Dossiq parafering raised from the voorstel lifecycle transition',
			[
				'app' => Application::APP_ID,
				'proposal' => $proposalId,
				'approvalRouteId' => $raised['approvalRouteId'],
			]
		);

		return array_merge($objectData, $raised);
	}//end execute()

	/**
	 * The voorstel's uuid, wherever the payload happens to carry it.
	 *
	 * OpenRegister hands a lifecycle action the object payload, and the
	 * identifier sits under `id` there, under `uuid` on some read paths, and
	 * inside `@self` on a rendered one. Reading only the first would make the
	 * action fail on payload shapes that are perfectly valid.
	 *
	 * @param array<string, mixed> $objectData The payload after the transition.
	 * @param array<string, mixed> $previousData The payload before it.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private function proposalId(array $objectData, array $previousData): string {
		foreach ([$objectData, $previousData] as $payload) {
			$self = ($payload['@self'] ?? []);
			if (is_array($self) === false) {
				$self = [];
			}

			foreach ([($payload['id'] ?? ''), ($payload['uuid'] ?? ''), ($self['id'] ?? ''), ($self['uuid'] ?? '')] as $candidate) {
				$value = trim((string)$candidate);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end proposalId()
}//end class
