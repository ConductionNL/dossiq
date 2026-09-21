<?php

/**
 * Dossiq case lifecycle action provider.
 *
 * Answers OpenRegister's `GET /api/objects/{id}/available-actions` for the
 * `case` schema, so a client — the `stages` timeline widget in
 * @conduction/nextcloud-vue above all — can render a case's real moves without
 * learning dossiq's REST surface.
 *
 * A case's state machine is DATA, not schema: every caseType carries its own
 * workflowTemplate, whose transitions an administrator edits. OpenRegister's
 * static `transitions` map and its `graph` mode can express neither, which is
 * what dossiq#1678 ruled. Provider mode is the third answer: OpenRegister asks
 * this class, exactly as it already asks {@see BezwaarDeadlineGuard} whether a
 * move is permitted.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Lifecycle;

use OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway;
use OCA\Dossiq\Service\Cases\ExternalHome;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Publishes a case's available status transitions on OpenRegister's vocabulary,
 * and takes the move a client picks off that list.
 *
 * 🔑 IT DERIVES NOTHING. Every entry comes out of
 * {@see StatusTransitionService::getAvailableTransitions()} — the same reader
 * the POST path validates against in `execute()`. A second derivation here
 * would eventually offer a move the write refuses, and the user would meet
 * that disagreement as a stage that highlights on hover and then fails.
 *
 * The same rule governs the write half. `availableActions()` reads and mutates
 * nothing; `execute()` hands the move straight to
 * {@see StatusTransitionService::execute()}, which owns the guard
 * re-evaluation, the optimistic version lock, the closing result, the
 * statusRecord and the side-effect dispatch. This class adds no validation of
 * its own on either side. Its ONE job on the write path is to say which of
 * OpenRegister's three failures the engine just had, because the engine's own
 * vocabulary does not distinguish them and a handler's next move depends on
 * which it was.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseActionProvider implements LifecycleActionProviderInterface {

	/**
	 * The engine's sentinel for a case it could not load.
	 */
	private const CASE_NOT_FOUND = 'case_not_found';

	/**
	 * Every failure of the engine's that is a REFUSAL and not a breakage.
	 *
	 * 🔴 THIS LIST IS THE WHOLE POINT OF THE CLASS ON THE WRITE PATH, AND IT IS
	 * A CLOSED LIST ON PURPOSE. `StatusTransitionService::execute()` reports
	 * every failure as a `RuntimeException` carrying a snake_case sentinel, and
	 * OpenRegister reads an ordinary `RuntimeException` as "the object refused
	 * that move" and answers 422. So a template that will not parse, a register
	 * that is not configured and a storage layer that is down all arrive at a
	 * handler as "that move is not allowed" unless they are re-thrown as
	 * {@see LifecycleProviderException}. A handler told that tries a different
	 * move and concludes the process forbids it, which is a lie about the
	 * process rather than an error message.
	 *
	 * Hence the polarity: only a sentinel NAMED here is a refusal, and anything
	 * else — an unrecognised sentinel, a `TypeError`, an `Error` — is a
	 * breakage. Defaulting the other way would hide the next failure mode the
	 * engine grows behind a 422.
	 *
	 * Each of these four is a refusal because the engine reached a verdict: the
	 * case loaded, the transition resolved, and a rule said no.
	 *
	 * - `transition_from_status_mismatch`: the case is no longer in the status
	 *   the transition leaves from. The client's timeline is stale.
	 * - `transition_unauthorized`: the caller is not in the group the
	 *   transition's `authorization` list names.
	 * - `transition_conflict`: the optimistic lock lost — another transition
	 *   landed between the read and the write.
	 * - `result_type_required`: a closing move arrived without a result, the
	 *   input `availableActions()` publishes for exactly this reason.
	 *
	 * {@see GuardFailedException} is the fifth refusal and is not listed: it is
	 * matched by type, because it carries the failed guards a client renders.
	 */
	private const REFUSALS = [
		'transition_from_status_mismatch',
		'transition_unauthorized',
		'transition_conflict',
		'result_type_required',
	];

	/**
	 * Constructor.
	 *
	 * @param StatusTransitionService $transitionEngine The single reader of a case's available moves.
	 * @param OpenRegisterGrantsGateway $grants The reader of OpenRegister's effective grants.
	 * @param ExternalHome $externalHome Whether the work on this case happens in another application.
	 * @param CaseActionList $actions The acts a case offers, and what blocks them.
	 * @param LoggerInterface $logger Logger for provider diagnostics.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly StatusTransitionService $transitionEngine,
		private readonly OpenRegisterGrantsGateway $grants,
		private readonly ExternalHome $externalHome,
		private readonly CaseActionList $actions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List the moves this case offers its current reader.
	 *
	 * 🔴 WHY NOTHING ESCAPES, AND WHY IT STILL THROWS.
	 *
	 * A `\Throwable` out of a provider is answered by OpenRegister with HTTP
	 * 502, and the timeline then renders every stage disabled. So nothing
	 * leaves here uncaught. But swallowing to an empty list would be worse
	 * than the 502, not better: an empty list is a LEGITIMATE answer — a case
	 * parked in a terminal status genuinely offers no moves — so a swallowed
	 * failure and a correct answer would be the same bytes, and the widget
	 * would present a broken lookup as a finished case. That is precisely the
	 * mistake {@see \OCA\Dossiq\Controller\StatusTransitionController::available()}
	 * makes when it catches `\Throwable` into a 500 with a static message, and
	 * it is deliberately NOT inherited here.
	 *
	 * So: catch, log with the case id, and rethrow as
	 * `LifecycleProviderException`, the type the interface documents and the
	 * one OpenRegister maps to 502. The one case that returns empty instead is
	 * a case that loaded and simply has nowhere to go.
	 *
	 * @param array<string, mixed> $object The loaded case payload at its current state.
	 * @param string $userId The uid of the caller.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>,label?:string,blocked?:bool}>
	 *
	 * @throws LifecycleProviderException When the case cannot be identified, cannot be
	 *                                    read, or the transition engine fails.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function availableActions(array $object, string $userId): array {
		$caseId = $this->caseIdOf(object: $object);
		if ($caseId === '') {
			throw new LifecycleProviderException(
				message: 'Dossiq case lifecycle provider: the object carries no case id.'
			);
		}

		$caller = $this->callerOf(userId: $userId);

		try {
			$available = $this->transitionEngine->getAvailableTransitions(
				caseId: $caseId,
				userId: $caller,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq case lifecycle provider: reading available transitions failed',
				['exception' => $e, 'caseId' => $caseId],
			);
			throw new LifecycleProviderException(
				message: 'Dossiq case lifecycle provider: available transitions could not be read.',
				code: 0,
				previous: $e,
			);
		}

		// `getAvailableTransitions()` answers with an EMPTY `current` block and
		// nothing else when it could not load the case at all — storage down,
		// register misconfigured, object gone. That is a failure to answer, not
		// an answer of "no moves", and the two are told apart here because only
		// `current` distinguishes them: a case that loaded always carries its
		// statusId, name and colour, even when its status is blank.
		$current = ($available['current'] ?? []);
		if (is_array($current) === false || $current === []) {
			throw new LifecycleProviderException(
				message: sprintf('Dossiq case lifecycle provider: case "%s" could not be read.', $caseId)
			);
		}

		$actions = $this->actions->publishAll(transitions: (array)($available['transitions'] ?? []));

		// OpenRegister's grants, beside dossiq's own guards (REQ-CGP-02).
		//
		// The two authorities answer different questions and neither replaces
		// the other: dossiq's guards say whether this MOVE is allowed from
		// here, OpenRegister's grants say whether this CALLER may write this
		// object at all. A case a reader may not write offers them no move,
		// whatever the workflow says, so a refusal from OpenRegister empties
		// the list rather than greying it: the requirement is that an action
		// they may not take is not offered.
		//
		// 🔑 The verdict is OpenRegister's, read and not derived. When it
		// cannot be had — the app is absent, or predates openregister#3726 —
		// the gateway answers null and the list is published exactly as it was
		// before this change. Falling closed on an absent authority would lock
		// every handler out of every case the day the app is disabled.
		if ($this->grants->refusesTheWrite(caseId: $caseId, userId: $caller) === true) {
			return [];
		}

		// A case homed in another application (REQ-HAND-04). The acts stay in
		// the list and come back BLOCKED, carrying the application that holds
		// the work: an act that vanished would read as a permission problem
		// and send somebody to the rights matrix for an afternoon.
		$actions = $this->actions->honourExternalHome(actions: $actions, object: $object);

		// A case whose type requires the leges first (REQ-FEE-04). Blocked
		// rather than hidden, for the same reason and with the same shape: the
		// sentence names the rule, so the handler goes to the payment panel
		// instead of to the rights matrix.
		return $this->actions->honourPaymentRule(actions: $actions, object: $object, caseId: $caseId);
	}//end availableActions()

	/**
	 * The uid to resolve the caller by, or null to let the session decide.
	 *
	 * An empty uid means OpenRegister had no session user to name. Handing
	 * that on as null lets the engine resolve the caller from IUserSession
	 * itself, which is the same identity the write path would use.
	 *
	 * @param string $userId The uid OpenRegister passed, possibly empty.
	 *
	 * @return string|null The uid, or null when there was none.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function callerOf(string $userId): ?string {
		if ($userId === '') {
			return null;
		}

		return $userId;
	}//end callerOf()

	/**
	 * Take one of the moves this provider offered.
	 *
	 * THE PROVIDER DOES THE WRITE. {@see StatusTransitionService::execute()}
	 * re-evaluates the transition's guards, takes the optimistic version lock,
	 * refuses a closing move that carries no result, writes the status record
	 * and dispatches the transition's side effects — all in one call, all in
	 * dossiq's own model. Nothing here repeats any of it: a second check would
	 * be the second authority this class exists to avoid, and could refuse a
	 * move the engine allows.
	 *
	 * THE RETURN VALUE IS THE ENGINE'S REPORT, VERBATIM. OpenRegister reads one
	 * key off it, `to`, and the engine names none: its `status` key is the
	 * outcome of the move, `ok` or `partial`, not a statusType. So `to` is
	 * deliberately NOT added.
	 * OpenRegister then reads the target state off the lifecycle field of the
	 * object it re-reads — `status`, per the case schema's
	 * `x-openregister-lifecycle` — which is stored truth. An echoed `to` would
	 * be a second claim about the same move, and the only way the two could
	 * ever differ is if the echo were wrong.
	 *
	 * @param array<string, mixed> $object The case payload as it stood before the move.
	 * @param string $userId The uid of the caller, empty when there is no session user.
	 * @param string $action The transition id, one `availableActions()` published.
	 * @param array<string, mixed> $data The inputs the caller supplied, keyed by field.
	 *
	 * @return array<string, mixed> The engine's report: `status`, `statusRecord`,
	 *                              `dispatchedActions`, `failedActions` and `version`.
	 *                              `status` is `ok`, or `partial` when the case moved
	 *                              and an action it dispatched failed; `failedActions`
	 *                              then lists those as `{type, error}`. A partial move
	 *                              is not a refusal and throws nothing.
	 *
	 * @throws RuntimeException When the move is refused — a guard said no, the case
	 *                          already moved, the caller may not, a result is missing.
	 * @throws LifecycleSubjectNotFoundException When the case has been deleted.
	 * @throws LifecycleProviderException When the engine could not answer at all.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
	 */
	public function execute(array $object, string $userId, string $action, array $data): array {
		$caseId = $this->caseIdOf(object: $object);
		if ($caseId === '') {
			// Not a refusal: OpenRegister only calls this with an object it
			// has just read, so a payload with no identity means dossiq or
			// OpenRegister is broken, not that the move is disallowed. Same
			// reading as `availableActions()`.
			throw new LifecycleProviderException(
				message: 'Dossiq case lifecycle provider: the object carries no case id.'
			);
		}

		// An empty uid means OpenRegister had no session user to name. Handing
		// that on as null lets the engine resolve the caller from IUserSession
		// itself, which is the identity its authorization gate would use.
		$caller = null;
		if ($userId !== '') {
			$caller = $userId;
		}

		// 🔴 THE BLOCK IS ENFORCED, NOT ADVISED. `availableActions()` publishes
		// these moves disabled, and a client that posts one anyway must meet
		// the same answer: a `blocked` flag nothing checks on the write path is
		// a suggestion, and the first client that ignores it moves a status
		// here that the specialist application never hears about. Thrown as a
		// plain RuntimeException, which OpenRegister answers 422 — the move was
		// refused, and nothing is broken.
		$elsewhere = $this->externalHome->whereTheWorkIs(case: $object);
		if ($elsewhere !== '') {
			throw new RuntimeException($elsewhere);
		}

		// The same enforcement for the payment rule, and for the same reason:
		// a flag the write path does not check is a suggestion, and the first
		// client that posts the move anyway hands a case to a handler the
		// gemeente has not been paid for. Refused as a RuntimeException, which
		// OpenRegister answers 422 with the sentence in `error` (ADR-050).
		$unpaid = $this->actions->whyPaymentBlocks(object: $object, caseId: $caseId);
		if ($unpaid !== '') {
			throw new RuntimeException($unpaid);
		}

		try {
			return $this->transitionEngine->execute(
				caseId: $caseId,
				transitionId: $action,
				comment: $this->textOf(data: $data, field: CaseActionList::COMMENT_INPUT),
				userId: $caller,
				resultTypeId: $this->textOf(data: $data, field: CaseActionList::CLOSING_INPUT),
			);
		} catch (Throwable $e) {
			throw $this->classify(failure: $e, object: $object, caseId: $caseId, action: $action);
		}
	}//end execute()

	/**
	 * Decide which of OpenRegister's three failures the engine just had.
	 *
	 * Returns the exception to throw rather than throwing it, so the whole
	 * mapping is one expression a test can drive one row at a time.
	 *
	 * @param Throwable $failure What the engine threw.
	 * @param array<string, mixed> $object The case payload OpenRegister handed over.
	 * @param string $caseId The case UUID.
	 * @param string $action The transition id that was asked for.
	 *
	 * @return Throwable The refusal unchanged, or the breakage/not-found type that wraps it.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function classify(Throwable $failure, array $object, string $caseId, string $action): Throwable {
		// A guard said no, and said why. Matched by type rather than by message
		// so the failed-guard snapshots reach the client intact.
		if ($failure instanceof GuardFailedException) {
			return $failure;
		}

		$code = '';
		if ($failure instanceof RuntimeException) {
			$code = $failure->getMessage();
		}

		if (in_array($code, self::REFUSALS, true) === true) {
			return $failure;
		}

		// 🔴 `case_not_found` IS FOUR FAILURES WEARING ONE NAME.
		// `CaseStatusStore::loadCase()` answers null when OpenRegister's
		// ObjectService is absent, when the register or case schema is not
		// configured, when `find()` throws — and when the row genuinely is not
		// there. It logs the difference and returns the same null, so the
		// engine cannot pass it on and neither can this class.
		//
		// The payload settles ONE of those four, and only that one: OpenRegister
		// hands over the object it just read, and a soft-deleted object carries
		// its deletion in `@self.deleted`. That is a case that is provably gone,
		// so it is the 404. Every other `case_not_found` is reported as a
		// breakage, which is the safer half of a coin this class cannot call: a
		// storage failure told as a breakage is retried, while a storage failure
		// told as "this case was deleted" sends a handler looking for a case
		// nobody removed.
		if ($code === self::CASE_NOT_FOUND && $this->isDeleted(object: $object) === true) {
			$this->logger->warning(
				'Dossiq case lifecycle provider: the case a move was asked for has been deleted',
				['caseId' => $caseId, 'action' => $action],
			);

			return new LifecycleSubjectNotFoundException(
				message: sprintf('Dossiq case lifecycle provider: case "%s" no longer exists.', $caseId),
				code: 0,
				previous: $failure,
			);
		}

		$this->logger->error(
			'Dossiq case lifecycle provider: the move could not be applied',
			['exception' => $failure, 'caseId' => $caseId, 'action' => $action, 'code' => $code],
		);

		return new LifecycleProviderException(
			message: sprintf('Dossiq case lifecycle provider: the move on case "%s" could not be applied.', $caseId),
			code: 0,
			previous: $failure,
		);
	}//end classify()

	/**
	 * Whether the payload OpenRegister handed over declares the case deleted.
	 *
	 * `@self.deleted` is OpenRegister's own soft-delete block, and its shape is
	 * read the way OpenRegister reads it: a live object carries null or an
	 * empty array there, so only a non-empty value means deleted. Reading it as
	 * `!== null` would call every object deleted, which is the mistake
	 * `ObjectEntity::isDeleted()` documents.
	 *
	 * @param array<string, mixed> $object The case payload.
	 *
	 * @return bool True when the payload says the case has been deleted.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function isDeleted(array $object): bool {
		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			return false;
		}

		$deleted = ($self['deleted'] ?? null);
		if (is_array($deleted) === true) {
			return $deleted !== [];
		}

		return (is_string($deleted) === true && trim($deleted) !== '');
	}//end isDeleted()

	/**
	 * Read one text input off the data OpenRegister collected.
	 *
	 * A value that is not text is dropped rather than cast. Casting an array
	 * would raise a conversion warning and pass the string `Array` on as a
	 * resultType, which the engine would store; dropping it leaves the engine
	 * to refuse the move for the input it is actually missing.
	 *
	 * @param array<string, mixed> $data The caller's inputs.
	 * @param string $field The field to read.
	 *
	 * @return string|null The trimmed value, null when absent, empty or not text.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function textOf(array $data, string $field): ?string {
		$value = ($data[$field] ?? null);

		$text = '';
		if (is_string($value) === true) {
			$text = trim($value);
		} elseif (is_int($value) === true) {
			$text = (string)$value;
		}

		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOf()

	/**
	 * Read the case's own identifier off the payload OpenRegister handed over.
	 *
	 * Both spellings are accepted because both occur: a read through
	 * ObjectService carries `@self.id`, while a payload assembled in-process
	 * carries a bare `id`.
	 *
	 * @param array<string, mixed> $object The case payload.
	 *
	 * @return string The case UUID, empty when the payload carries none.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function caseIdOf(array $object): string {
		$self = ($object['@self'] ?? []);
		$fromSelf = '';
		if (is_array($self) === true) {
			$fromSelf = (string)($self['id'] ?? ($self['uuid'] ?? ''));
		}

		return trim((string)($object['id'] ?? ($object['uuid'] ?? $fromSelf)));
	}//end caseIdOf()
}//end class
