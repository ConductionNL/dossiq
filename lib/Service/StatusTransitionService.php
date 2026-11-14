<?php

/**
 * Dossiq Status Transition Service.
 *
 * The single deterministic write path for `case.status` across Dossiq. The
 * REST API, the case detail UI, and the bezwaar/parafering/VTH workflow
 * specs all funnel transitions through `execute()`. Responsibilities:
 *
 *  - load the active workflow template per caseType (via WorkflowTemplateLoader)
 *  - evaluate every transition's guards (via GuardRegistry)
 *  - update `case.status` atomically with a `statusRecord` write
 *  - dispatch automatic actions sequentially (via SideEffectDispatcher)
 *  - replay transition history from the `statusRecord` chain
 *
 * The engine keeps the decision: which transition, in which order the checks
 * run, what is refused and what the caller is told. It asks two collaborators
 * for everything else. {@see Transitions\CaseDeclarations} reads what a case
 * declares about its status and its moves. {@see Transitions\CaseMoveLedger}
 * holds every read and write of the store, the closing result and the side
 * effects. {@see Transitions\TransitionAuthorizer} owns the OR-RBAC group gate
 * and {@see Transitions\TransitionSpecReader} the template dialects a
 * transition's guards and actions may be spelled in.
 *
 * Identity is ALWAYS derived from IUserSession when the caller does not pass
 * an explicit userId. Static error messages only — never bubble exception
 * detail to controllers or callers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\Contracts\StatusTransitionServiceInterface;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\Transitions\CaseDeclarations;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\TransitionAsk;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\Transitions\TransitionStep;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The status-transition engine.
 *
 * @spec openspec/changes/archive/2026-05-11-status-transition-engine/tasks.md#T10
 */
class StatusTransitionService implements StatusTransitionServiceInterface {

	/**
	 * Group ID used to gate admin-only free-form transitions. Matches the
	 * naming used elsewhere in Dossiq for the admin role.
	 *
	 * Re-exported from TransitionAuthorizer, which owns the group gate, so
	 * existing `StatusTransitionService::ADMIN_GROUP_ID` callers keep reading
	 * the single source of truth.
	 */
	public const ADMIN_GROUP_ID = TransitionAuthorizer::ADMIN_GROUP_ID;

	/**
	 * The move a free-form transition records, in this key order; the
	 * from-status, target, comment and actor are filled in per move.
	 *
	 * @var array<string, mixed>
	 */
	private const FREE_FORM_MOVE = [
		'fromStatus' => '',
		'toStatus' => '',
		'label' => 'Free-form transition',
		'comment' => null,
		'actor' => '',
		'evaluatedGuards' => [],
		'noWorkflowTemplate' => true,
	];

	/**
	 * Constructor.
	 *
	 * @param WorkflowTemplateLoader $templateLoader Active workflowTemplate loader
	 * @param GuardRegistry $guardRegistry Guard registry
	 * @param TransitionAuthorizer $authorizer OR-RBAC group gate
	 * @param TransitionSpecReader $specReader Guard/action shape reader
	 * @param IUserSession $userSession Current session
	 * @param LoggerInterface $logger Logger
	 * @param ProcessOwnedStatusRule $processOwnedStatus Refuses a hand-set status where the case type gives it to the process
	 * @param CaseDeclarations $declarations What the case declares about its status and its moves
	 * @param CaseMoveLedger $ledger What a move writes, and what earlier moves wrote
	 */
	public function __construct(
		private readonly WorkflowTemplateLoader $templateLoader,
		private readonly GuardRegistry $guardRegistry,
		private readonly TransitionAuthorizer $authorizer,
		private readonly TransitionSpecReader $specReader,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ProcessOwnedStatusRule $processOwnedStatus,
		private readonly CaseDeclarations $declarations,
		private readonly CaseMoveLedger $ledger,
	) {
	}//end __construct()

	/**
	 * Compute the set of transitions available to a user on a case.
	 *
	 * @param string $caseId Case UUID
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{transitions: array<int, array<string, mixed>>, current: array<string, mixed>}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getAvailableTransitions(string $caseId, ?string $userId = null): array {
		$userId = $this->resolveUserId(explicit: $userId);
		$case = $this->ledger->load(caseId: $caseId);
		if ($case === null) {
			return ['transitions' => [], 'current' => []];
		}

		$currentId = (string)($case['status'] ?? '');
		$transitions = $this->transitionsFor(case: $case);
		$result = $this->panelFor(case: $case, currentId: $currentId);
		if ($transitions === null) {
			return $result;
		}

		// Which moves are offered, and which are withheld and why, is ONE
		// decision about one transition asked five ways, and it lives in its
		// own class. This engine's job is what happens when a handler TAKES
		// one.
		$sorted = $this->declarations->offered(
			transitions: $transitions,
			case: $case,
			currentId: $currentId,
			userId: $userId,
		);

		$result['transitions'] = $sorted['transitions'];
		$result['withheld'] = $sorted['withheld'];

		return $result;
	}//end getAvailableTransitions()

	/**
	 * The transitions of the workflow a case runs on, or null when it runs on none.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int|string, mixed>|null The transitions, or null without a template or a transition list.
	 */
	private function transitionsFor(array $case): ?array {
		// 🔑 THE CASE, NOT ITS CASE TYPE. A case type may carry several routes,
		// and a case pinned to one of them must be offered that route's
		// transitions. Asking by case type alone returned whichever active
		// definition the store listed first.
		$template = $this->templateLoader->getTemplateForCase(case: $case);
		if ($template === null) {
			return null;
		}

		$transitions = $template['transitions'] ?? [];
		if (is_array($transitions) === false) {
			return null;
		}

		return $transitions;
	}//end transitionsFor()

	/**
	 * The status panel of a case, with no transitions offered or withheld yet.
	 *
	 * @param array<string, mixed> $case      The case.
	 * @param string               $currentId The status the case is in.
	 *
	 * @return array<string, mixed> The panel.
	 */
	private function panelFor(array $case, string $currentId): array {
		// What the status the case is in DECLARES, beside the moves out of it.
		// The handler asks both questions in the same breath — what can I do
		// here, and why is the thing I expected not on offer — so they are
		// answered in one round trip rather than two.
		$declared = $this->declarations->panel(case: $case);

		return [
			'transitions' => [],
			// Always present, even when nothing is withheld: a caller that had
			// to test for the key would read an absent list as "nothing is in
			// the way", which is the one wrong answer that looks right.
			'withheld' => [],
			// The name, the colour and what this status MEANS, written by an
			// administrator and rendered on the case: all three come off the
			// same row.
			'current' => array_merge(
				['statusId' => $currentId],
				$this->declarations->statusFacts(statusTypeId: $currentId),
				['waitingOn' => $declared['waitingOn'], 'dwell' => $declared['dwell']],
			),
			'derivation' => $declared['derivation'],
		];
	}//end panelFor()

	/**
	 * Execute a guarded transition.
	 *
	 * @param string $caseId Case UUID
	 * @param string $transitionId Transition id from the workflow the case runs on
	 * @param string|null $comment Optional free-form comment
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 * @param string|null $resultTypeId ResultType chosen for a closing transition
	 *
	 * @return array{
	 *     status: string,
	 *     statusRecord: array<string, mixed>,
	 *     dispatchedActions: array<int, array<string, mixed>>,
	 *     failedActions: array<int, array{type: string, error: string}>,
	 *     version: int,
	 * }
	 *         `status` is `ok`, or `partial` when the case moved and an action it
	 *         should have brought did not run. See `actionOutcome()`.
	 *
	 * @throws RuntimeException A GuardFailedException when server-side re-evaluation fails
	 *                          any guard; a RefusedException when the from-status or group
	 *                          gate rejects; otherwise when case/transition/template are not
	 *                          found, or a closing transition arrives without a result type
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function execute(
		string $caseId,
		string $transitionId,
		?string $comment,
		?string $userId = null,
		?string $resultTypeId = null,
	): array {
		$actor = $this->resolveUserId(explicit: $userId);
		$step = $this->stepFor(ask: new TransitionAsk(caseId: $caseId, transitionId: $transitionId, userId: $actor, resultTypeId: $resultTypeId));
		$eval = $this->assertTransitionAllowed(step: $step);

		return $this->carryOut(caseId: $caseId, step: $step, evaluatedGuards: $eval, comment: $comment);
	}//end execute()

	/**
	 * Write an allowed move, retime the status clocks, record it and dispatch its actions.
	 *
	 * @param string                           $caseId          Case UUID
	 * @param TransitionStep                   $step            The allowed move on the loaded case
	 * @param array<int, array<string, mixed>> $evaluatedGuards The guard evaluation results
	 * @param string|null                      $comment         Optional comment
	 *
	 * @return array{
	 *     status: string,
	 *     statusRecord: array<string, mixed>,
	 *     dispatchedActions: array<int, array<string, mixed>>,
	 *     failedActions: array<int, array{type: string, error: string}>,
	 *     version: int,
	 * }
	 *
	 * @throws RuntimeException When the transition names no target status, or the case changed since it was read
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function carryOut(string $caseId, TransitionStep $step, array $evaluatedGuards, ?string $comment): array {
		$toStatus = $step->toStatus();
		if ($toStatus === '') {
			throw new RuntimeException('transition_missing_to_status');
		}

		// H2: Optimistic concurrency guard — re-load the case immediately before writing
		// and abort if its status changed since we read it (concurrent transition executed
		// between our guard evaluation and our save).
		$caseAtSave = $this->ledger->reloadUnchanged(caseId: $caseId, readVersion: $step->readVersion(), currentId: $step->currentId());

		[$saved, $savedVersion] = $this->writeMove(step: $step, caseAtSave: $caseAtSave);

		// Stop the clock on the status the case left, start one on the status
		// it entered when that status declares a maximum. After the save, and
		// never before it: a timer armed for a move that then failed to persist
		// would breach about a status the case is not in.
		$this->declarations->retime(caseId: $caseId, toStatus: $toStatus);

		[$record, $dispatched] = $this->recordAndDispatch(
			step: $step->withCaseData(caseData: $saved),
			move: $step->move(comment: $comment, evaluatedGuards: $evaluatedGuards)
		);

		return $this->executed(caseId: $caseId, record: $record, dispatched: $dispatched, savedVersion: $savedVersion);
	}//end carryOut()

	/**
	 * The answer of an executed move.
	 *
	 * @param string                           $caseId       Case UUID
	 * @param array<string, mixed>             $record       The status record
	 * @param array<int, array<string, mixed>> $dispatched   The dispatched actions
	 * @param int                              $savedVersion The case's new version
	 *
	 * @return array{
	 *     status: string,
	 *     statusRecord: array<string, mixed>,
	 *     dispatchedActions: array<int, array<string, mixed>>,
	 *     failedActions: array<int, array{type: string, error: string}>,
	 *     version: int,
	 * }
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function executed(string $caseId, array $record, array $dispatched, int $savedVersion): array {
		$outcome = $this->actionOutcome(dispatched: $dispatched, caseId: $caseId);

		return [
			'status' => $outcome['status'],
			'statusRecord' => $record,
			'dispatchedActions' => $dispatched,
			'failedActions' => $outcome['failedActions'],
			'version' => $savedVersion,
		];
	}//end executed()

	/**
	 * Load the case and the transition a move asks for.
	 *
	 * @param TransitionAsk $ask The move as asked.
	 *
	 * @return TransitionStep The move on the loaded case.
	 *
	 * @throws RuntimeException When the case or the transition does not exist.
	 */
	private function stepFor(TransitionAsk $ask): TransitionStep {
		$loaded = $this->ledger->load(caseId: $ask->caseId);
		if ($loaded === null) {
			throw new RuntimeException('case_not_found');
		}

		$transition = $this->templateLoader->getTransitionForCase(case: $loaded, transitionId: $ask->transitionId);
		if ($transition === null) {
			throw new RuntimeException('transition_not_found');
		}

		return new TransitionStep(ask: $ask, caseData: $loaded, transition: $transition);
	}//end stepFor()

	/**
	 * What to tell the caller happened, and which actions did not.
	 *
	 * 🔴 THIS USED TO BE DROPPED ON THE FLOOR, AND THAT IS WHAT MADE A BROKEN
	 * CHECKLIST INVISIBLE. `SideEffectDispatcher` records `['ok' => false]`
	 * for every action that failed, and both return sites answered
	 * `'status' => 'ok'` regardless. On the day every `createTask` was refused
	 * for want of an acting identity, twelve refusals were written into the
	 * status record and the API still answered 200 with `ok`: a handler moved
	 * a case, was told it had worked, and got none of the work the phase asks
	 * for.
	 *
	 * 🔴 A FAILED ACTION DOES NOT ROLL THE TRANSITION BACK, AND SHOULD NOT.
	 * The status mutation is committed before any side effect runs
	 * (REQ-STE-5-002) and the statusRecord is already written, so by the time
	 * an action fails the case HAS moved and its history says so. Undoing that
	 * would mean deleting an audit record to make a task failure tidy.
	 *
	 * So the answer is not an error either. A 5xx would tell the handler the
	 * move did not happen, which is false. `partial` is the honest third
	 * answer: the move happened, some of the work it should have brought did
	 * not, and the caller is told which. The HTTP status stays 200.
	 *
	 * @param array<int, array<string, mixed>> $dispatched The dispatcher's result rows.
	 * @param string                           $caseId     The case, for the log line.
	 *
	 * @return array{status: string, failedActions: array<int, array{type: string, error: string}>}
	 *         `ok` with no failures, or `partial` with the failures in dispatch order.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function actionOutcome(array $dispatched, string $caseId): array {
		$outcome = ['status' => 'ok', 'failedActions' => []];
		foreach ($dispatched as $row) {
			if (is_array($row) === false) {
				continue;
			}

			// ABSENT reads as ok, because that is what the key means when a
			// caller substitutes the dispatcher: the real one always sets it.
			if (($row['ok'] ?? true) !== false) {
				continue;
			}

			$outcome['failedActions'][] = [
				'type' => (string)($row['type'] ?? ''),
				'error' => (string)($row['error'] ?? 'action_failed'),
			];
		}

		if ($outcome['failedActions'] !== []) {
			$outcome['status'] = 'partial';
			$this->logger->warning(
				'StatusTransitionService: the status moved but {count} of its actions did not run',
				['count' => count($outcome['failedActions']), 'case' => $caseId, 'failed' => $outcome['failedActions']],
			);
		}

		return $outcome;
	}//end actionOutcome()

	/**
	 * Settle everything this move writes on the case, and write it, once.
	 *
	 * Three things land in ONE save, and each of them was a separate write at
	 * some point in this method's history: the closing result, the dwell
	 * bookkeeping, and the status itself. Two saves is two chances for one of
	 * them not to happen, and the one that goes missing is always the
	 * bookkeeping rather than the status, so the case ends up in a status whose
	 * arrival nothing recorded.
	 *
	 * REQ-STE-12: a closing transition carries its result or it does not
	 * happen, and the refusal is raised HERE, before the mutation, so a case
	 * refused for want of a result is left untouched rather than closed and
	 * then patched.
	 *
	 * REQ-STE-5-002: the status mutation happens before any side effect. The
	 * `@self.version` read at the top of `execute()` travels with the payload,
	 * so the store still refuses a write that another transition got in front
	 * of.
	 *
	 * @param TransitionStep       $step       The move on the case as first read.
	 * @param array<string, mixed> $caseAtSave The case as re-read immediately before writing.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The saved case and its new version.
	 *
	 * @throws RuntimeException When a closing transition carries no result type.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function writeMove(TransitionStep $step, array $caseAtSave): array {
		$toStatus = $step->toStatus();
		$caseAtSave = $this->ledger->close(
			case: $caseAtSave,
			caseId: $step->ask->caseId,
			toStatus: $toStatus,
			resultTypeId: $step->ask->resultTypeId,
		);

		$caseAtSave = $this->declarations->stamp(case: $caseAtSave, toStatus: $toStatus);

		return $this->ledger->save(case: $caseAtSave, toStatus: $toStatus, readVersion: $step->readVersion());
	}//end writeMove()

	/**
	 * Write the statusRecord for a transition and run its side effects.
	 *
	 * Both halves live here because the record's id is the correlation key the
	 * dispatched actions are written back onto: splitting them would mean
	 * passing that id back and forth for no gain.
	 *
	 * @param TransitionStep       $step The move on the SAVED case (side effects read the new status)
	 * @param array<string, mixed> $move What the status record says (TransitionStep::move())
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
	 *         The statusRecord and the dispatched-action results
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function recordAndDispatch(TransitionStep $step, array $move): array {
		$record = $this->ledger->record(caseId: $step->ask->caseId, move: $move);

		// The status the case just entered brings its own work. The checklist
		// actions go FIRST, so the tasks a phase asks for exist before whatever
		// the transition itself does with them: a notification that lists the
		// case's open tasks is otherwise sent one dispatch too early.
		$actions = array_merge(
			$this->declarations->checklistActions(statusTypeId: $step->toStatus(), case: $step->caseData, actor: $step->ask->userId),
			$this->specReader->extractActions(transition: $step->transition),
		);
		$dispatched = $this->ledger->dispatch(actions: $actions, case: $step->caseData, move: $move, record: $record);

		// Update the statusRecord with the actual dispatched-action results.
		return [$this->ledger->writeBack(record: $record, dispatched: $dispatched), $dispatched];
	}//end recordAndDispatch()

	/**
	 * Re-evaluate every server-side precondition for a transition.
	 *
	 * @param TransitionStep $step The move on the loaded case, with the acting user
	 *
	 * @return array<int, array<string, mixed>> The guard evaluation results
	 *
	 * @throws RuntimeException A RefusedException when the from-status or group authorization
	 *                          gate rejects, a GuardFailedException when any guard fails
	 */
	private function assertTransitionAllowed(TransitionStep $step): array {
		$step->assertStartsFromCurrent();
		$ask = $step->ask;

		// The dependency is re-asked on the server, for the reason the guards
		// are: the list a browser holds was true when it was fetched, and the
		// advice request can have arrived since. Withholding is the courtesy;
		// this is the rule.
		$this->declarations->assertOpen(transition: $step->transition, case: $step->caseData, caseId: $ask->caseId, userId: $ask->userId);

		$step->assertGroupAuthorized(
			authorized: $this->authorizer->isTransitionGroupAuthorized(transition: $step->transition, userId: $ask->userId)
		);

		// Defence in depth — re-evaluate guards on the server side.
		$eval = $this->guardRegistry->evaluateAll(
			guards: $this->evaluateGuards(transition: $step->transition),
			case: $step->caseData,
			userId: $ask->userId,
		);
		$failed = array_values(array_filter($eval, static fn (array $guard): bool => $guard['passed'] === false));
		// @phpstan-ignore greaterThan.alwaysFalse (PHPDoc type marks passed as bool, but runtime values may differ)
		if (count($failed) > 0) {
			$this->logger->info('StatusTransitionService: guards failed', ['caseId' => $ask->caseId, 'transitionId' => $ask->transitionId]);
		}

		$step->assertGuardsPassed(failed: $failed);

		return $eval;
	}//end assertTransitionAllowed()

	/**
	 * Execute an admin-only free-form transition for caseTypes without an active workflow template.
	 *
	 * @param string $caseId Case UUID
	 * @param string $toStatusId Target statusType UUID
	 * @param string|null $comment Optional free-form comment
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{
	 *     status: string,
	 *     statusRecord: array<string, mixed>,
	 *     dispatchedActions: array<int, array<string, mixed>>,
	 *     failedActions: array<int, array{type: string, error: string}>,
	 * }
	 *
	 * @throws RuntimeException When the caller is not in the admin group or the target is invalid
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function executeFreeForm(string $caseId, string $toStatusId, ?string $comment, ?string $userId = null): array {
		$userId = $this->resolveUserId(explicit: $userId);
		$case = $this->caseForAdmin(caseId: $caseId, userId: $userId);
		$this->assertHandSettable(case: $case, toStatusId: $toStatusId);

		$move = array_replace(
			self::FREE_FORM_MOVE,
			['fromStatus' => (string)($case['status'] ?? ''), 'toStatus' => $toStatusId, 'comment' => $comment, 'actor' => $userId],
		);
		[$case] = $this->ledger->save(
			case: $this->declarations->stamp(case: $case, toStatus: $toStatusId),
			toStatus: $toStatusId,
		);
		$this->declarations->retime(caseId: $caseId, toStatus: $toStatusId);

		$record = $this->ledger->record(caseId: $caseId, move: $move);
		$dispatched = $this->ledger->dispatch(
			actions: $this->declarations->checklistActions(statusTypeId: $toStatusId, case: $case, actor: $userId),
			case: $case,
			move: $move,
			record: $record,
		);

		$outcome = $this->actionOutcome(dispatched: $dispatched, caseId: $caseId);

		return [
			'status' => $outcome['status'],
			'statusRecord' => $record,
			'dispatchedActions' => $dispatched,
			'failedActions' => $outcome['failedActions'],
		];
	}//end executeFreeForm()

	/**
	 * The case a free-form transition writes, for an administrator only.
	 *
	 * @param string $caseId Case UUID
	 * @param string $userId The acting user UID
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RuntimeException When the caller is not in the admin group or the case is not found
	 */
	private function caseForAdmin(string $caseId, string $userId): array {
		if ($this->authorizer->isAdmin(userId: $userId) === false) {
			throw new RuntimeException('forbidden_admin_only');
		}

		$case = $this->ledger->load(caseId: $caseId);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		return $case;
	}//end caseForAdmin()

	/**
	 * Refuse a free-form target the case type does not allow to be set by hand.
	 *
	 * @param array<string, mixed> $case       The case.
	 * @param string               $toStatusId Target statusType UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the case type owns its status or the target is not one of its statuses
	 */
	private function assertHandSettable(array $case, string $toStatusId): void {
		$caseTypeId = (string)($case['caseType'] ?? '');

		// REQ-LIFE-03. A free-form transition is a status written because an
		// administrator said so rather than because the process moved, and a
		// case type that declares `processOwnedStatus` accepts none of those.
		// It sits HERE and not on `execute()`: a declared transition IS the
		// process moving the status, so the rule there would refuse the one
		// way a process-owned status is allowed to change.
		//
		// What it does not reach is a PATCH sent straight to OpenRegister.
		// That boundary is the grants gateway's, and a guard that claimed it
		// would read complete and not be.
		$this->processOwnedStatus->requireHandSetAllowed(caseTypeId: $caseTypeId);

		$this->ledger->assertStatusOfCaseType(caseTypeId: $caseTypeId, statusTypeId: $toStatusId);
	}//end assertHandSettable()

	/**
	 * Return the chronological history of transitions for a case.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array{history: array<int, array<string, mixed>>, replayable: bool}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function replay(string $caseId): array {
		$list = $this->ledger->history(caseId: $caseId);
		if ($list === null) {
			return ['history' => [], 'replayable' => false];
		}

		usort(
			$list,
			static function (array $left, array $right): int {
				$leftAt = (string)($left['createdAt'] ?? ($left['@self']['createdAt'] ?? ''));
				$rightAt = (string)($right['createdAt'] ?? ($right['@self']['createdAt'] ?? ''));
				return strcmp($leftAt, $rightAt);
			},
		);

		return ['history' => $list, 'replayable' => true];
	}//end replay()

	/**
	 * Check if the current (or given) user is in the dossiq admin group.
	 *
	 * @param string $userId UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isAdmin(string $userId): bool {
		return $this->authorizer->isAdmin(userId: $userId);
	}//end isAdmin()

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * The guards a transition is subject to: its own, plus the implicit ones.
	 *
	 * The status checklist is appended to EVERY transition rather than left to
	 * the template, because the list it enforces is authored on the status. A
	 * guard a template has to remember is a guard the next case type forgets,
	 * and a required item that only holds one road out of a phase holds
	 * nothing at all.
	 *
	 * It goes LAST, so a role guard still hides a transition before the
	 * checklist has anything to say about it.
	 *
	 * @param array<string, mixed> $transition The transition definition
	 *
	 * @return array<int, array<string, mixed>> The guards to evaluate
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function evaluateGuards(array $transition): array {
		// The implicit guards (the status checklist, the status capacity and
		// the walked approval) come from the one list the offer and the move
		// both read.
		return $this->specReader->guardsWithImplicit(
			transition: $transition,
			approvalsWired: $this->guardRegistry->knows(type: GuardRegistry::APPROVAL_GATE),
			entryGuardWired: $this->guardRegistry->knows(type: GuardRegistry::STATUS_ENTRY_REQUIREMENTS),
		);
	}//end evaluateGuards()

	/**
	 * Resolve a user UID either from the explicit parameter or IUserSession.
	 *
	 * @param string|null $explicit Caller-supplied UID, or null
	 *
	 * @return string
	 */
	private function resolveUserId(?string $explicit): string {
		if ($explicit !== null && $explicit !== '') {
			return $explicit;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end resolveUserId()
}//end class
