<?php

/**
 * Dossiq CaseRouter: routes a case to one person, and takes it back.
 *
 * `route()` resolves a routing rule against a case through
 * {@see \OCA\Dossiq\Service\RoleResolverService} (weight, team position and
 * area included), writes the person it picked as the case's assignee, keeps
 * the rule and the moment on the case as `routing`, and arms the rule's
 * take-back window when it declares one.
 *
 * `takeBack()` runs when that window breaches. It hands the case back to the
 * pool only when nobody accepted it: the case is still with the person it was
 * routed to, that routing is still the current one, nobody accepted it (their
 * first status move or edit, decision 164) and the case is still open. It then
 * routes the case again by the SAME rule, never to the person it came back
 * from, and appends the take-back to `routingTakeBacks`: who had it, why it
 * came back, who has it now (REQ-RTP-03).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseRouterInterface;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Picks the person, writes the routing onto the case, and takes it back.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class CaseRouter implements CaseRouterInterface {
	use SearchesObjects;

	/**
	 * The rule keys a routing keeps; anything else in a request is dropped.
	 *
	 * @var string[]
	 */
	public const RULE_KEYS = ['strategy', 'roleType', 'roleTypes', 'team', 'areaTeams', 'fallback', 'takeBackAfter'];

	/**
	 * Take-back outcome: the case went to another member of the pool.
	 *
	 * @var string
	 */
	public const TAKEN_BACK = 'taken-back';

	/**
	 * Take-back outcome: the pool has nobody else, so the case stays and that is recorded.
	 *
	 * @var string
	 */
	public const KEPT = 'kept';

	/**
	 * Take-back outcome: nothing to take back (accepted, moved on, closed or gone).
	 *
	 * @var string
	 */
	public const NOT_DUE = 'not-due';

	/**
	 * The reason a take-back records.
	 *
	 * @var string
	 */
	public const REASON_NOT_ACCEPTED = 'not-accepted-within-window';

	/**
	 * How often the rule is asked again for somebody other than the holder.
	 *
	 * A round robin moves on by one per ask; a pool of one answers the holder
	 * every time, and then the case stays.
	 *
	 * @var int
	 */
	private const MAX_ASKS = 3;

	/**
	 * Build the router.
	 *
	 * @param SettingsService     $settingsService The register, the case schema and the object service.
	 * @param RoleResolverService $resolver        Resolves a rule to people.
	 * @param AreaRouting         $area            Says which team the area picked and whether the fallback was used.
	 * @param TakeBackWindow      $window          Arms and cancels the take-back timer.
	 * @param ITimeFactory        $time            Now.
	 * @param LoggerInterface     $logger          Logs a routing that found nobody.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly RoleResolverService $resolver,
		private readonly AreaRouting $area,
		private readonly TakeBackWindow $window,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Route a case by a rule.
	 *
	 * @param string               $caseId The case id.
	 * @param array<string, mixed> $rule   The routing rule.
	 *
	 * @return array{caseId: string, assignee: string, team: string, areaFallbackUsed: bool, reason: string, takeBack: string}
	 *
	 * @throws RuntimeException When OpenRegister is absent or the case does not exist.
	 * @throws RefusedException When the rule names a strategy nobody registered (422).
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function route(string $caseId, array $rule): array {
		$case = $this->caseOrFail(caseId: $caseId);

		try {
			return $this->routeCase(case: $case, rule: $this->ruleOf(rule: $rule), exclude: '');
		} catch (RoutingStrategyMissingException $e) {
			throw new RefusedException(
				rule: 'routing-strategy-unknown',
				sentence: $e->getMessage(),
				status: RefusedException::STATUS_UNPROCESSABLE,
				previous: $e,
			);
		}
	}//end route()

	/**
	 * Hand a case back to its pool when its take-back window breached.
	 *
	 * @param string $caseId   The case id.
	 * @param string $routedTo The person the window was armed for.
	 * @param string $routedAt The routing moment the window was armed for (ATOM).
	 *
	 * @return string TAKEN_BACK, KEPT or NOT_DUE.
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function takeBack(string $caseId, string $routedTo, string $routedAt): string {
		$case = $this->findCase(caseId: $caseId);
		if ($case === null || $routedTo === '') {
			return self::NOT_DUE;
		}

		$routing = $this->routingOf(case: $case);
		if ($this->stillUnaccepted(case: $case, routing: $routing, routedTo: $routedTo, routedAt: $routedAt) === false) {
			return self::NOT_DUE;
		}

		return $this->handBack(dueCase: $case, routedTo: $routedTo);
	}//end takeBack()

	/**
	 * Route a due case to someone else in its pool, and record the take-back.
	 *
	 * @param array<string, mixed> $dueCase  The case whose window breached.
	 * @param string               $routedTo The person the window was armed for.
	 *
	 * @return string TAKEN_BACK or KEPT.
	 */
	private function handBack(array $dueCase, string $routedTo): string {
		$rule    = $this->ruleOf(rule: (array) ($this->routingOf(case: $dueCase)['rule'] ?? []));
		$records = $this->takeBacksOf(case: $dueCase);
		$record  = $this->takeBackRecord(routedTo: $routedTo, rule: $rule);

		$this->resolver->invalidateCache(caseId: (string) $dueCase['id']);
		$answer = $this->routeCase(case: $dueCase, rule: $rule, exclude: $routedTo);
		$record = $this->withOutcome(record: $record, assignee: $answer['assignee']);
		$this->write(case: $dueCase, changes: ['routingTakeBacks' => [...$records, $record]]);

		return $record['outcome'];
	}//end handBack()

	/**
	 * The take-back record before its outcome is known.
	 *
	 * @param string               $routedTo The person the window was armed for.
	 * @param array<string, mixed> $rule     The sanitised rule.
	 *
	 * @return array<string, mixed>
	 */
	private function takeBackRecord(string $routedTo, array $rule): array {
		$record = [
			'from'   => $routedTo,
			'to'     => $routedTo,
			'reason' => self::REASON_NOT_ACCEPTED,
			'at'     => $this->now()->format(DATE_ATOM),
		];
		$window = $this->window->windowOf(rule: $rule);
		if ($window !== null) {
			$record['window'] = $window;
		}

		return $record;
	}//end takeBackRecord()

	/**
	 * The take-back record with who got the case and the outcome.
	 *
	 * @param array<string, mixed> $record   The record before its outcome.
	 * @param string               $assignee Who the case was routed to, or '' for nobody.
	 *
	 * @return array<string, mixed>
	 */
	private function withOutcome(array $record, string $assignee): array {
		if ($assignee === '') {
			// Nobody else in the pool. The case stays where it is, the window is
			// not armed again, and the record says so: a take-back that found no
			// one is a fact about the pool somebody has to look at.
			$record['outcome'] = self::KEPT;
			return $record;
		}

		$record['to']      = $assignee;
		$record['outcome'] = self::TAKEN_BACK;

		return $record;
	}//end withOutcome()

	/**
	 * Resolve, write and arm.
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param array<string, mixed> $rule    The sanitised rule.
	 * @param string               $exclude A person who may not get it, or ''.
	 *
	 * @return array{caseId: string, assignee: string, team: string, areaFallbackUsed: bool, reason: string, takeBack: string}
	 */
	private function routeCase(array $case, array $rule, string $exclude): array {
		$caseId = (string) $case['id'];
		$area   = $this->area->resolve(rule: $rule, case: $case, caseType: null);
		$pick   = $this->pick(rule: $rule, case: $case, exclude: $exclude);
		$answer = ['caseId' => $caseId] + $this->areaAnswer(area: $area, pick: $pick);

		if ($pick === '') {
			return $this->nobodyFound(answer: $answer, exclude: $exclude);
		}

		$routedAt = $this->now();
		$routing  = [
			'rule'       => $rule,
			'routedTo'   => $pick,
			'routedAt'   => $routedAt->format(DATE_ATOM),
		];
		$this->write(case: $case, changes: $this->assignment(answer: $answer, routing: $routing));

		$answer['takeBack'] = $this->window->arm(caseId: $caseId, rule: $rule, routedTo: $pick, routedAt: $routedAt);
		return $answer;
	}//end routeCase()

	/**
	 * The routing answer after the case id: who, which team and why, before any window is armed.
	 *
	 * @param array<string, mixed> $area The area routing's answer.
	 * @param string               $pick The person picked, or ''.
	 *
	 * @return array{assignee: string, team: string, areaFallbackUsed: bool, reason: string, takeBack: string}
	 */
	private function areaAnswer(array $area, string $pick): array {
		return [
			'assignee'         => $pick,
			'team'             => (string) $area['team'],
			'areaFallbackUsed' => ($area['fallbackUsed'] === true),
			'reason'           => (string) $area['reason'],
			'takeBack'         => TakeBackWindow::SKIPPED,
		];
	}//end areaAnswer()

	/**
	 * The answer when the rule found nobody: logged, and with a reason.
	 *
	 * @param array<string, mixed> $answer  The answer so far.
	 * @param string               $exclude A person who may not get it, or ''.
	 *
	 * @return array{caseId: string, assignee: string, team: string, areaFallbackUsed: bool, reason: string, takeBack: string}
	 */
	private function nobodyFound(array $answer, string $exclude): array {
		$this->logger->info('Dossiq routing: the rule found nobody to route the case to', ['case' => $answer['caseId'], 'exclude' => $exclude]);
		if ($answer['reason'] === '') {
			$answer['reason'] = 'no-candidate';
		}

		return $answer;
	}//end nobodyFound()

	/**
	 * The changes that assign the case to the person picked and record the routing.
	 *
	 * @param array<string, mixed> $answer  The answer so far.
	 * @param array<string, mixed> $routing The routing record.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(array $answer, array $routing): array {
		return [
			'assignee'         => $answer['assignee'],
			'areaFallbackUsed' => $answer['areaFallbackUsed'],
			'routing'          => $routing,
		];
	}//end assignment()

	/**
	 * The first person the rule names who is not excluded.
	 *
	 * @param array<string, mixed> $rule    The rule.
	 * @param array<string, mixed> $case    The case.
	 * @param string               $exclude A person who may not get it, or ''.
	 *
	 * @return string The person, or '' when the rule names nobody else.
	 */
	private function pick(array $rule, array $case, string $exclude): string {
		for ($ask = 0; $ask < self::MAX_ASKS; $ask++) {
			foreach ($this->resolver->resolve(rule: $rule, case: $case) as $participant) {
				$participant = trim((string) $participant);
				if ($participant !== '' && $participant !== $exclude) {
					return $participant;
				}
			}

			if ($exclude === '') {
				return '';
			}

			// Ask again: the resolver remembers its answer per case, and a
			// round robin only moves on when it is asked.
			$this->resolver->invalidateCache(caseId: (string) $case['id']);
		}

		return '';
	}//end pick()

	/**
	 * Whether the routing the window was armed for still stands unaccepted.
	 *
	 * @param array<string, mixed> $case     The fresh case.
	 * @param array<string, mixed> $routing  Its routing record.
	 * @param string               $routedTo The person the window was armed for.
	 * @param string               $routedAt The moment the window was armed for.
	 *
	 * @return bool
	 */
	private function stillUnaccepted(array $case, array $routing, string $routedTo, string $routedAt): bool {
		if (trim((string) ($case['endDate'] ?? '')) !== '') {
			return false;
		}

		if ((string) ($case['assignee'] ?? '') !== $routedTo || (string) ($routing['routedTo'] ?? '') !== $routedTo) {
			return false;
		}

		if ($routedAt !== '' && (string) ($routing['routedAt'] ?? '') !== $routedAt) {
			return false;
		}

		return trim((string) ($routing['acceptedAt'] ?? '')) === '';
	}//end stillUnaccepted()

	/**
	 * The rule with only the keys a routing keeps.
	 *
	 * @param array<string, mixed> $rule The rule as given.
	 *
	 * @return array<string, mixed>
	 */
	private function ruleOf(array $rule): array {
		return array_intersect_key($rule, array_flip(self::RULE_KEYS));
	}//end ruleOf()

	/**
	 * The case's routing record.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function routingOf(array $case): array {
		$routing = ($case['routing'] ?? null);
		if (is_array($routing) === true) {
			return $routing;
		}

		return [];
	}//end routingOf()

	/**
	 * The case's earlier take-backs.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, mixed>
	 */
	private function takeBacksOf(array $case): array {
		$records = ($case['routingTakeBacks'] ?? null);
		if (is_array($records) === true) {
			return array_values($records);
		}

		return [];
	}//end takeBacksOf()

	/**
	 * The case, or an exception naming why there is none.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When OpenRegister is absent or the case does not exist.
	 */
	private function caseOrFail(string $caseId): array {
		$case = $this->findCase(caseId: $caseId);
		if ($case === null) {
			throw new RuntimeException('The case could not be read: '.$caseId);
		}

		return $case;
	}//end caseOrFail()

	/**
	 * The case, read fresh, or null.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return null;
		}

		$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
		if ($case === null) {
			return null;
		}

		$case['id'] = (string) ($case['id'] ?? ($case['uuid'] ?? ($case['@self']['id'] ?? $caseId)));
		return $case;
	}//end findCase()

	/**
	 * Patch the case with these fields only.
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param array<string, mixed> $changes The fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is absent.
	 */
	private function write(array $case, array $changes): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$this->patchObjectAsArray(
			objectService: $objectService,
			register: (string) $this->settingsService->getConfigValue('register'),
			schema: (string) $this->settingsService->getConfigValue('case_schema'),
			id: (string) $case['id'],
			changes: $changes,
		);
	}//end write()

	/**
	 * Now.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable($this->time->getDateTime()->format(DATE_ATOM));
	}//end now()
}//end class
