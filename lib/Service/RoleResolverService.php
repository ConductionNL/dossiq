<?php

/**
 * Dossiq Role Resolver Service
 *
 * Central engine that resolves a `routingRule` plus a `case` to an ordered
 * set of participant references. Owns:
 *   - Legacy normalisation: `assigneeRole` -> single-role,
 *     `allowedRoles` -> or-set.
 *   - Strategy dispatch via StrategyRegistry.
 *   - Delegation substitution + cycle detection on `role.delegate`.
 *   - APCu cache layer keyed by `(ruleHash, caseId)` for 60s.
 *
 * Callers: task list builder, status-transition engine,
 * /api/cases/{id}/reroute controller.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\RoleResolverServiceInterface;
use OCA\Dossiq\Service\Routing\AreaRouting;
use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use OCA\Dossiq\Service\Routing\RoleResolutionCache;
use OCA\Dossiq\Service\Routing\RoutingStrategyMissingException;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCA\Dossiq\Service\Support\ObjectArray;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Central role-routing engine.
 *
 * @spec openspec/changes/archive/2026-05-11-role-based-step-routing/tasks.md#T02
 */
class RoleResolverService implements RoleResolverServiceInterface {
	use RefusesWhenIndeterminate;

	/**
	 * Default strategy name when normalising legacy fields.
	 */
	public const STRATEGY_SINGLE_ROLE = 'single-role';

	/**
	 * Strategy name used to normalise `allowedRoles`.
	 */
	public const STRATEGY_OR_SET = 'or-set';

	/**
	 * Constructor.
	 *
	 * @param StrategyRegistry $registry Strategy registry
	 * @param SettingsService $settingsService Bridge to ObjectService + config
	 * @param RoleResolutionCache $cache What a rule last resolved to against a case
	 * @param RoleDelegationResolver $delegation Active-window delegate substitution
	 * @param AreaRouting $area Turns the area held on a case into the team a rule routes to
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly StrategyRegistry $registry,
		private readonly SettingsService $settingsService,
		private readonly RoleResolutionCache $cache,
		private readonly RoleDelegationResolver $delegation,
		private readonly AreaRouting $area,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalise a step or transition into a concrete routing rule.
	 *
	 * Order of precedence:
	 *   1. Explicit `routingRule` object on the step/transition.
	 *   2. Legacy `assigneeRole` (UUID) -> single-role.
	 *   3. Legacy `allowedRoles` (UUID array) -> or-set.
	 *
	 * Returns null when nothing routable is declared (caller decides default).
	 *
	 * @param array<string, mixed> $entry The step or transition payload
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function normaliseRule(array $entry): ?array {
		$rule = $entry['routingRule'] ?? null;
		if (is_array($rule) === true && isset($rule['strategy']) === true) {
			return $rule;
		}

		$assigneeRole = (string)($entry['assigneeRole'] ?? '');
		if ($assigneeRole !== '') {
			return [
				'strategy' => self::STRATEGY_SINGLE_ROLE,
				'roleType' => $assigneeRole,
			];
		}

		return $this->orSetRule(entry: $entry);
	}//end normaliseRule()

	/**
	 * The or-set rule a legacy `allowedRoles` array declares, or null.
	 *
	 * @param array<string, mixed> $entry The step or transition payload
	 *
	 * @return array<string, mixed>|null
	 */
	private function orSetRule(array $entry): ?array {
		$allowedRoles = $entry['allowedRoles'] ?? null;
		if (is_array($allowedRoles) === true && $allowedRoles !== []) {
			return [
				'strategy' => self::STRATEGY_OR_SET,
				'roleTypes' => array_values(
					array_map(
						static fn ($value): string => (string)$value,
						$allowedRoles,
					)
				),
			];
		}

		return null;
	}//end orSetRule()

	/**
	 * Resolve a routing rule against a case.
	 *
	 * @param array<string, mixed> $rule The (already normalised) routing rule
	 * @param array<string, mixed> $case The case object (must include id, caseType)
	 *
	 * @return array<int, string> Ordered participant refs (post-delegation)
	 *
	 * @throws RoutingStrategyMissingException When the rule's strategy is unknown
	 * @throws RefusedException When the case's roles could not be read at all
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function resolve(array $rule, array $case): array {
		$strategyName = $this->registeredStrategy(rule: $rule);
		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		$remembered = $this->cache->get(rule: $rule, caseId: $caseId);
		if ($remembered !== null) {
			return $remembered;
		}

		$run = $this->routingRun(case: $case, caseId: $caseId);
		$run->strategy = $strategyName;
		$strategy = $this->registry->get($strategyName);

		// THE AREA DECIDES THE TEAM BEFORE THE STRATEGY PICKS THE PERSON.
		// `AreaRouting` shipped with REQ-RTP-04 and nothing called it, so a
		// rule carrying `areaTeams` routed as though the map were not there
		// and a case carrying `districtTeam` was ignored. It answers
		// conservatively: a rule with no map and a case with no team come back
		// unchanged, so this is a no-op for every rule written before it.
		$run->rule = $this->withArea(rule: $rule, case: $case);
		$primary = $this->withFallback(run: $run, primary: $strategy->resolve($run->rule, $case, $run->roles));

		return $this->delegated(run: $run, members: $primary);
	}//end resolve()

	/**
	 * The rule's strategy name; refused when no such strategy is registered.
	 *
	 * @param array<string, mixed> $rule The normalised routing rule.
	 *
	 * @return string The strategy name.
	 *
	 * @throws RoutingStrategyMissingException When the rule's strategy is unknown.
	 */
	private function registeredStrategy(array $rule): string {
		$strategyName = (string)($rule['strategy'] ?? '');
		if ($this->registry->has($strategyName) === false) {
			throw new RoutingStrategyMissingException(
				message: sprintf('Routing strategy "%s" is not registered', $strategyName)
			);
		}

		return $strategyName;
	}//end registeredStrategy()

	/**
	 * A resolution of the case, with the roles read for it.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param string               $caseId Its id.
	 *
	 * @return RoleRoutingRun The run.
	 *
	 * @throws RefusedException When the case's roles could not be read at all.
	 */
	private function routingRun(array $case, string $caseId): RoleRoutingRun {
		$run = new RoleRoutingRun();
		$run->case = $case;
		$run->caseId = $caseId;
		$run->roles = $this->loadCaseRoles(caseId: $caseId);

		return $run;
	}//end routingRun()

	/**
	 * The strategy's members, or the rule's fallback role when it found nobody.
	 *
	 * The hierarchical strategy has its own fallback and never falls back here.
	 *
	 * @param RoleRoutingRun     $run     The resolution.
	 * @param array<int, string> $primary What the strategy answered.
	 *
	 * @return array<int, string> The members.
	 */
	private function withFallback(RoleRoutingRun $run, array $primary): array {
		$fallback = (string)($run->rule['fallback'] ?? '');
		if ($primary === [] && $fallback !== '' && $run->strategy !== 'hierarchical') {
			return $this->registry
				->get(self::STRATEGY_SINGLE_ROLE)
				->resolve(['strategy' => self::STRATEGY_SINGLE_ROLE, 'roleType' => $fallback], $run->case, $run->roles);
		}

		return $primary;
	}//end withFallback()

	/**
	 * The members after delegation, remembered, and an empty set said out loud.
	 *
	 * @param RoleRoutingRun     $run     The resolution.
	 * @param array<int, string> $members The members before delegation.
	 *
	 * @return array<int, string> The members after delegation.
	 */
	private function delegated(RoleRoutingRun $run, array $members): array {
		$resolved = $this->delegation->apply(participants: $members, roles: $run->roles);
		$this->cache->remember(rule: $run->rule, caseId: $run->caseId, members: $resolved);
		if ($resolved === []) {
			$this->logger->info(
				'Dossiq: routing rule resolved to empty set',
				['event' => 'RoleRoutingEmpty', 'rule' => $run->rule, 'caseId' => $run->caseId, 'app' => AppIdentity::APP_ID],
			);
		}

		return $resolved;
	}//end delegated()

	/**
	 * The rule as the case's area rewrites it.
	 *
	 * 🔴 THE CASE TYPE IS NOT READ HERE, and that is a stated gap rather than
	 * an oversight. `AreaRouting::resolve()` takes an optional case type for
	 * one thing only, the `areaFallbackRoleType` a case outside every boundary
	 * routes to, and this service holds no case-type reader. Passing null means
	 * an unplaceable case falls back to the rule's own role rather than the
	 * type's, which is the behaviour of every rule written before REQ-RTP-04.
	 * The fallback half lands with the resolver that writes `district` onto a
	 * case, which is still dark for want of an address id the case does not
	 * carry.
	 *
	 * @param array<string, mixed> $rule The routing rule.
	 * @param array<string, mixed> $case The case, carrying its held area.
	 *
	 * @return array<string, mixed> The rule, with the area's team and role on it.
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	private function withArea(array $rule, array $case): array {
		$resolved = $this->area->resolve(rule: $rule, case: $case, caseType: null);

		$team = trim((string)$resolved['team']);
		if ($team !== '') {
			$rule['team'] = $team;
		}

		$roleType = trim((string)$resolved['roleType']);
		if ($roleType !== '') {
			$rule['roleType'] = $roleType;
		}

		if ($resolved['fallbackUsed'] === true) {
			// SAID OUT LOUD. An address that could not be placed routes
			// somewhere plausible, and without this line the only sign is a
			// case sitting with a team that has never seen it.
			$this->logger->info(
				'Dossiq: a case was routed by the area fallback',
				[
					'event' => 'RoleRoutingAreaFallback',
					'caseId' => (string)($case['id'] ?? ($case['uuid'] ?? '')),
					'reason' => (string)$resolved['reason'],
					'app' => AppIdentity::APP_ID,
				],
			);
		}

		return $rule;
	}//end withArea()

	/**
	 * Whether the given user is permitted to execute against the rule.
	 *
	 * Convenience for status-transition guard evaluation.
	 *
	 * @param array<string, mixed> $rule The routing rule
	 * @param array<string, mixed> $case The case
	 * @param string $userId The candidate user id
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function canExecute(array $rule, array $case, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		try {
			$allowed = $this->resolve(rule: $rule, case: $case);
		} catch (RoutingStrategyMissingException $e) {
			$this->logger->warning(
				'Dossiq: routing guard rejected — missing strategy: ' . $e->getMessage(),
			);
			return false;
		}

		return in_array($userId, $allowed, true);
	}//end canExecute()

	/**
	 * Invalidate the cache for every rule against a case.
	 *
	 * Called by the role-mutation listener.
	 *
	 * @param string $caseId The case UUID/id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function invalidateCache(string $caseId): void {
		$this->cache->forget(caseId: $caseId);
	}//end invalidateCache()

	/**
	 * Load the role records bound to a case via ObjectService.
	 *
	 * @param string $caseId The case id
	 *
	 * @return array<int, array<string, mixed>> The role rows, empty when the case has none.
	 *
	 * @throws RefusedException When the role register could not be read at all.
	 */
	private function loadCaseRoles(string $caseId): array {
		if ($caseId === '') {
			return [];
		}

		$source = $this->roleSource();
		if ($source === null) {
			return [];
		}

		return $this->rowsOf(records: $this->readRoles(source: $source, caseId: $caseId));
	}//end loadCaseRoles()

	/**
	 * Where the case roles are read: the object service, register and role schema; null when any is missing.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The source.
	 */
	private function roleSource(): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('role_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end roleSource()

	/**
	 * The stored role records of one case; refused when the register cannot be read.
	 *
	 * @param array{0: object, 1: string, 2: string} $source The object service, register and role schema.
	 * @param string                                 $caseId The case UUID.
	 *
	 * @return mixed What the register answered.
	 *
	 * @throws RefusedException When the role register could not be read at all.
	 */
	private function readRoles(array $source, string $caseId): mixed {
		[$objectService, $register, $schema] = $source;

		// OpenRegister's ObjectService::findAll() takes ONE config array. This
		// call used to pass ($register, $schema, $filters) positionally, which
		// is a TypeError against `array $config` — and the catch that stood
		// here turned that TypeError into an empty role list, so stored case
		// roles were never loaded and rule resolution silently fell through to
		// its other sources. An empty role list is also how a routing rule
		// resolves to nobody, which is why the read now refuses instead.
		return $this->readOrRefuse(
			read: fn (): mixed => $objectService->findAll(['filters' => ['register' => $register, 'schema' => $schema, 'case' => $caseId]]),
			what: 'the roles on case ' . $caseId,
			rule: 'case-roles-unreadable',
			sentence: 'The roles on this case could not be read, so the routing cannot be worked out right now.',
		);
	}//end readRoles()

	/**
	 * The records as rows; one that reads as nothing is left out.
	 *
	 * @param mixed $records What the register answered.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(mixed $records): array {
		$rows = [];
		foreach ((array)$records as $record) {
			$row = $this->toArray(value: $record);
			if ($row !== []) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Coerce ObjectService return to plain array.
	 *
	 * @param mixed $value The record (entity or array)
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		return ObjectArray::of(value: $value);
	}//end toArray()

	/**
	 * Throw a runtime exception with a static message.
	 *
	 * Helper for callers that wrap resolution; kept for symmetry.
	 *
	 * @param string $message Failure label
	 *
	 * @return never
	 *
	 * @throws RuntimeException
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function fail(string $message): never {
		throw new RuntimeException($message);
	}//end fail()
}//end class
