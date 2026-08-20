<?php

/**
 * Procest parafering step activator.
 *
 * Activates one step of a parafeerroute: locates the step in the route
 * snapshot, resolves its abstract actor into the concrete set of users that
 * may act on it, and records the activation. Split out of ParafeerRouteService
 * so that service keeps the transition sequencing while the actor question —
 * the one place a routing rule meets the shared RoleResolverService — has a
 * single owner.
 *
 * For role-typed actors the step's actor UUID is treated as the `roleType`
 * parameter of an implicit single-role rule, which is what makes delegation
 * and workload routing apply to parafering steps for free. Resolution failure
 * degrades to the literal actor rather than to an empty set: a step with no
 * resolvable actor would silently strand the voorstel.
 *
 * @category Service
 * @package  OCA\Procest\Service\Parafering
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Parafering;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\RoleResolverService;
use OCA\Procest\Service\Routing\RoutingStrategyMissingException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Activates a parafering step and resolves its concrete actor set.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T07
 */
class ParaferingStepActivator {
	/**
	 * Constructor.
	 *
	 * @param RoleResolverService $roleResolver Central role-routing engine.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly RoleResolverService $roleResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Activate the step with the given order within a route snapshot.
	 *
	 * A step order that is absent from the snapshot is a no-op, matching the
	 * pre-split behaviour.
	 *
	 * @param array<string, mixed> $proposal The voorstel.
	 * @param int $step The step order to activate.
	 * @param array<int, array<string, mixed>> $steps The decoded routeSnapshot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/role-based-step-routing/tasks.md#T07
	 */
	public function activateStep(array $proposal, int $step, array $steps): void {
		$stepInfo = null;
		foreach ($steps as $candidate) {
			if ((int)($candidate['order'] ?? 0) === $step) {
				$stepInfo = $candidate;
				break;
			}
		}

		if ($stepInfo === null) {
			return;
		}

		$resolvedActors = $this->resolveStepActors(stepInfo: $stepInfo, proposal: $proposal);

		$this->logger->info(
			'Procest: activated parafering step {step} of voorstel {voorstelId} for actor {actor}',
			[
				'step' => $step,
				'voorstelId' => $proposal['id'] ?? $proposal['uuid'] ?? '',
				'actor' => (string)($stepInfo['actor'] ?? ''),
				'resolved' => $resolvedActors,
				'app' => Application::APP_ID,
			],
		);
	}//end activateStep()

	/**
	 * Resolve the concrete actor set for a step.
	 *
	 * For role-typed actors, the step's actor UUID is treated as the
	 * `roleType` parameter of an implicit single-role rule and dispatched to
	 * the shared RoleResolverService — this inherits delegation + workload
	 * features automatically. For user-typed actors the original UUID is
	 * returned as-is.
	 *
	 * @param array<string, mixed> $stepInfo The step from routeSnapshot.
	 * @param array<string, mixed> $proposal The voorstel object (provides caseRef + caseType).
	 *
	 * @return array<int, string> The resolved actor UIDs.
	 *
	 * @spec openspec/changes/role-based-step-routing/tasks.md#T07
	 */
	public function resolveStepActors(array $stepInfo, array $proposal): array {
		$actorType = (string)($stepInfo['actorType'] ?? 'user');
		$actor = (string)($stepInfo['actor'] ?? '');
		if ($actor === '') {
			return [];
		}

		if ($actorType !== 'role') {
			return [$actor];
		}

		$caseRef = (string)($proposal['case'] ?? ($proposal['zaak'] ?? ''));
		if ($caseRef === '') {
			return [$actor];
		}

		$case = ['id' => $caseRef, 'caseType' => (string)($proposal['caseType'] ?? '')];
		$rule = $stepInfo['routingRule'] ?? null;
		if (is_array($rule) === false || isset($rule['strategy']) === false) {
			$rule = [
				'strategy' => RoleResolverService::STRATEGY_SINGLE_ROLE,
				'roleType' => $actor,
			];
		}

		try {
			return $this->roleResolver->resolve($rule, $case);
		} catch (RoutingStrategyMissingException $e) {
			$this->logger->warning(
				'Procest: parafering step references unknown routing strategy: ' . $e->getMessage(),
			);
			return [$actor];
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: failed to resolve parafering step actors: ' . $e->getMessage(),
			);
			return [$actor];
		}
	}//end resolveStepActors()
}//end class
