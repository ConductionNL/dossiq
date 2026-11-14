<?php

/**
 * Dossiq KCC BelplanRoutingService
 *
 * Routes inbound KCC calls to the best-matched specialist based on a
 * `belplan` configuration: keuzemenu options → vaardigheid mapping →
 * specialist availability lookup → overflow handling. The service is
 * stateless: callers pass in the belplan + the current specialist pool
 * snapshot and receive a routing decision.
 *
 * Real wiring to a pipelinq KCC switchboard happens at the OpenConnector
 * edge; this service owns the routing-decision algorithm so it can be
 * unit-tested deterministically without an upstream KCC dependency.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * Belplan-driven KCC call routing.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */
class BelplanRoutingService {
	/**
	 * Default overflow thresholds (seconds wachttijd / queue length).
	 */
	public const DEFAULT_OVERFLOW_WACHTTIJD = 180;
	public const DEFAULT_OVERFLOW_WACHTRIJ_LENGTE = 5;

	/**
	 * Status values that count as "available" for routing.
	 *
	 * @var array<int, string>
	 */
	public const AVAILABLE_STATUSES = ['beschikbaar', 'available', 'idle'];

	/**
	 * The route of a call that needs no skill: nobody, no escalation.
	 *
	 * @var array<string, mixed>
	 */
	private const NO_SKILL_ROUTE = [
		'destinationSpecialistId' => null,
		'escalatieFlag' => false,
		'estimatedWaitTime' => 0,
		'vaardigheid' => '',
		'candidatePool' => 0,
	];

	/**
	 * Match a phone number against a belplan's triggerNummer (E.164 or local).
	 *
	 * @param string $phoneNumber The inbound number.
	 * @param array<int, array<string, mixed>> $belplannen The list of belplan records.
	 *
	 * @return array<string, mixed>|null The matched belplan or null when none matches.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function getActiveBelplan(string $phoneNumber, array $belplannen): ?array {
		$normalised = $this->normalisePhone(phoneNumber: $phoneNumber);

		foreach ($belplannen as $bp) {
			if (($bp['isActive'] ?? true) === false) {
				continue;
			}

			$trigger = $this->normalisePhone(phoneNumber: (string)($bp['triggerNumber'] ?? ''));
			if ($trigger === '') {
				continue;
			}

			if ($trigger === $normalised || str_ends_with($normalised, $trigger) === true) {
				return $bp;
			}
		}

		return null;
	}//end getActiveBelplan()

	/**
	 * Resolve a vaardigheid (skill) for the chosen menu option.
	 *
	 * @param array<string, mixed> $belplan The belplan record.
	 * @param string|int $menuSelection The 1-based menu key OR option label.
	 *
	 * @return string The vaardigheid, or '' when not resolvable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function resolveVaardigheid(array $belplan, string|int $menuSelection): string {
		$steps = $belplan['routingSteps'] ?? [];
		if (is_array($steps) === false) {
			return '';
		}

		// Numeric selection: 1-based index into routeringStappen[].
		if (is_int($menuSelection) === true || ctype_digit((string)$menuSelection) === true) {
			return $this->byIndex(steps: $steps, menuSelection: $menuSelection);
		}

		return $this->byLabel(steps: $steps, menuSelection: $menuSelection);
	}//end resolveVaardigheid()

	/**
	 * The vaardigheid of the routing step a numeric menu choice picks.
	 *
	 * @param array      $steps         The belplan's routing steps.
	 * @param string|int $menuSelection The 1-based menu choice.
	 *
	 * @return string The vaardigheid, or '' when no step has that number.
	 */
	private function byIndex(array $steps, string|int $menuSelection): string {
		$idx = ((int)$menuSelection) - 1;
		if (isset($steps[$idx]) === true && is_array($steps[$idx]) === true) {
			return (string)($steps[$idx]['vaardigheid'] ?? '');
		}

		return '';
	}//end byIndex()

	/**
	 * The vaardigheid of the routing step whose label matches the choice, ignoring case.
	 *
	 * @param array      $steps         The belplan's routing steps.
	 * @param string|int $menuSelection The menu choice.
	 *
	 * @return string The vaardigheid, or '' when no label matches.
	 */
	private function byLabel(array $steps, string|int $menuSelection): string {
		// Otherwise, match the option label case-insensitively.
		$needle = mb_strtolower((string)$menuSelection);
		foreach ($steps as $step) {
			if (is_array($step) === false) {
				continue;
			}

			$label = mb_strtolower((string)($step['label'] ?? ''));
			if ($label === $needle) {
				return (string)($step['vaardigheid'] ?? '');
			}
		}

		return '';
	}//end byLabel()

	/**
	 * Pick the best specialist for a given vaardigheid from the supplied pool.
	 *
	 * The algorithm:
	 *   1. Filter specialists who have the vaardigheid AND are available.
	 *   2. Pick the one with the lowest huidigeWachtrijLengte (queue length).
	 *   3. On ties, the one with the lowest gemiddeldeBehandelduur wins.
	 *   4. When no specialist is available AND the busy queue would push
	 *      callers past overflow thresholds → return a generalist routing
	 *      decision flagged with escalatieAanbevolen=true.
	 *
	 * @param string $vaardigheid The required skill.
	 * @param array<int, array<string, mixed>> $pool Specialist availability snapshot.
	 * @param int $overflowWachttijd Seconds threshold.
	 * @param int $maxQueueLengte Queue threshold.
	 *
	 * @return array{destinationSpecialistId: string|null, escalatieFlag: bool,
	 *               estimatedWaitTime: int, vaardigheid: string,
	 *               candidatePool: int}
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function routeCall(
		string $vaardigheid,
		array $pool,
		int $overflowWachttijd = self::DEFAULT_OVERFLOW_WACHTTIJD,
		int $maxQueueLengte = self::DEFAULT_OVERFLOW_WACHTRIJ_LENGTE,
	): array {
		if ($vaardigheid === '') {
			return self::NO_SKILL_ROUTE;
		}

		$routing = $this->routingFor(vaardigheid: $vaardigheid, pool: $pool, overflowWachttijd: $overflowWachttijd, maxQueueLengte: $maxQueueLengte);

		return $this->routeAmong(routing: $routing);
	}//end routeCall()

	/**
	 * Build the routing request with the candidates that hold the skill.
	 *
	 * @param string                           $vaardigheid       The required skill.
	 * @param array<int, array<string, mixed>> $pool              The specialist pool.
	 * @param int                              $overflowWachttijd The overflow wait time.
	 * @param int                              $maxQueueLengte    The maximum queue length.
	 *
	 * @return CallRouting
	 */
	private function routingFor(string $vaardigheid, array $pool, int $overflowWachttijd, int $maxQueueLengte): CallRouting {
		$routing = new CallRouting();
		$routing->vaardigheid = $vaardigheid;
		$routing->overflowWachttijd = $overflowWachttijd;
		$routing->maxQueueLengte = $maxQueueLengte;
		$routing->candidates = $this->filterCandidates(pool: $pool, vaardigheid: $vaardigheid);

		return $routing;
	}//end routingFor()

	/**
	 * Route among the candidates: escalate, overflow or pick a specialist.
	 *
	 * @param CallRouting $routing The routing request.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function routeAmong(CallRouting $routing): array {
		if (count($routing->candidates) === 0) {
			return $this->escalatedRoute(vaardigheid: $routing->vaardigheid);
		}

		$available = $this->availableOf(candidates: $routing->candidates);

		// No-one available: overflow check.
		if (count($available) === 0) {
			return $this->overflowRoute(routing: $routing);
		}

		return $this->pickedRoute(routing: $routing, available: $available);
	}//end routeAmong()

	/**
	 * The route when no specialist in the pool holds the skill.
	 *
	 * @param string $vaardigheid The required skill.
	 *
	 * @return array<string, mixed> The escalated route.
	 */
	private function escalatedRoute(string $vaardigheid): array {
		return [
			'destinationSpecialistId' => null,
			'escalatieFlag' => true,
			'estimatedWaitTime' => 0,
			'vaardigheid' => $vaardigheid,
			'candidatePool' => 0,
		];
	}//end escalatedRoute()

	/**
	 * The candidates whose status says they can take a call now.
	 *
	 * @param array<int, array<string, mixed>> $candidates The specialists with the skill.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function availableOf(array $candidates): array {
		return array_filter(
			$candidates,
			static function (array $c): bool {
				$status = mb_strtolower((string)($c['status'] ?? ''));
				return in_array($status, self::AVAILABLE_STATUSES, true);
			}
		);
	}//end availableOf()

	/**
	 * The route when nobody is available: escalate when the wait or the queue is too long.
	 *
	 * @param CallRouting $routing The call being routed.
	 *
	 * @return array<string, mixed>
	 */
	private function overflowRoute(CallRouting $routing): array {
		$minQueue = $this->minQueueLength(pool: $routing->candidates);
		$estWait = $minQueue * $this->avgHandlingDuration(pool: $routing->candidates);
		$overflow = ($estWait > $routing->overflowWachttijd) || ($minQueue > $routing->maxQueueLengte);

		return [
			'destinationSpecialistId' => null,
			'escalatieFlag' => $overflow,
			'estimatedWaitTime' => $estWait,
			'vaardigheid' => $routing->vaardigheid,
			'candidatePool' => count($routing->candidates),
		];
	}//end overflowRoute()

	/**
	 * The route to the available specialist with the shortest queue, then the shortest handling.
	 *
	 * @param CallRouting                      $routing   The call being routed.
	 * @param array<int, array<string, mixed>> $available The available candidates.
	 *
	 * @return array<string, mixed>
	 */
	private function pickedRoute(CallRouting $routing, array $available): array {
		usort(
			$available,
			static function (array $a, array $b): int {
				$queueA = (int)($a['currentQueueLength'] ?? 0);
				$queueB = (int)($b['currentQueueLength'] ?? 0);
				if ($queueA !== $queueB) {
					return $queueA <=> $queueB;
				}

				$durationA = (int)($a['averageHandlingDuration'] ?? 0);
				$durationB = (int)($b['averageHandlingDuration'] ?? 0);
				return $durationA <=> $durationB;
			}
		);

		$picked = $available[0];
		$picked['currentQueueLength'] = (int)($picked['currentQueueLength'] ?? 0);
		$picked['averageHandlingDuration'] = (int)($picked['averageHandlingDuration'] ?? 0);

		return [
			'destinationSpecialistId' => (string)($picked['employeeId'] ?? ($picked['id'] ?? '')),
			'escalatieFlag' => false,
			'estimatedWaitTime' => $picked['currentQueueLength'] * $picked['averageHandlingDuration'],
			'vaardigheid' => $routing->vaardigheid,
			'candidatePool' => count($routing->candidates),
		];
	}//end pickedRoute()

	/**
	 * Normalise a phone number: keep digits + leading +.
	 *
	 * @param string $phoneNumber Input.
	 *
	 * @return string Normalised form.
	 */
	private function normalisePhone(string $phoneNumber): string {
		$clean = preg_replace('/[^0-9+]/', '', $phoneNumber);
		return $clean ?? '';
	}//end normalisePhone()

	/**
	 * Filter the pool to specialists having the requested vaardigheid.
	 *
	 * @param array<int, array<string, mixed>> $pool Pool snapshot.
	 * @param string $vaardigheid Required skill.
	 *
	 * @return array<int, array<string, mixed>> The matching candidates.
	 */
	private function filterCandidates(array $pool, string $vaardigheid): array {
		$candidates = [];
		$needle = mb_strtolower($vaardigheid);
		foreach ($pool as $sp) {
			$skills = $sp['expertises'] ?? ($sp['vaardigheden'] ?? []);
			if (is_array($skills) === false) {
				continue;
			}

			foreach ($skills as $skill) {
				if (mb_strtolower((string)$skill) === $needle) {
					$candidates[] = $sp;
					break;
				}
			}
		}

		return $candidates;
	}//end filterCandidates()

	/**
	 * Smallest queue length across a pool.
	 *
	 * @param array<int, array<string, mixed>> $pool The pool.
	 *
	 * @return int The min queue length.
	 */
	private function minQueueLength(array $pool): int {
		$min = PHP_INT_MAX;
		foreach ($pool as $sp) {
			$queue = (int)($sp['currentQueueLength'] ?? 0);
			if ($queue < $min) {
				$min = $queue;
			}
		}

		if ($min === PHP_INT_MAX) {
			return 0;
		}

		return $min;
	}//end minQueueLength()

	/**
	 * Average behandelduur across a pool (seconds).
	 *
	 * @param array<int, array<string, mixed>> $pool The pool.
	 *
	 * @return int The average duur.
	 */
	private function avgHandlingDuration(array $pool): int {
		if (count($pool) === 0) {
			return 0;
		}

		$sum = 0;
		foreach ($pool as $sp) {
			$sum += (int)($sp['averageHandlingDuration'] ?? 0);
		}

		return (int)round($sum / count($pool));
	}//end avgBehandelduur()
}//end class
