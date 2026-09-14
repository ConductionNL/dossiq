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

use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
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
	 * The input a closing move must carry.
	 *
	 * `execute()` refuses a transition into a final status without a
	 * resultType, before it mutates anything. Declaring the input here is what
	 * lets a client collect the answer first instead of meeting the refusal.
	 */
	private const CLOSING_INPUT = 'resultTypeId';

	/**
	 * The free-form note a move may carry.
	 */
	private const COMMENT_INPUT = 'comment';

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
	 * @param CaseResultWriter $resultWriter Decides whether a target status closes the case.
	 * @param LoggerInterface $logger Logger for provider diagnostics.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function __construct(
		private readonly StatusTransitionService $transitionEngine,
		private readonly CaseResultWriter $resultWriter,
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

		// An empty uid means OpenRegister had no session user to name. Handing
		// that on as null lets the engine resolve the caller from IUserSession
		// itself, which is the same identity the write path would use.
		$caller = null;
		if ($userId !== '') {
			$caller = $userId;
		}

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

		$actions = [];
		foreach ((array)($available['transitions'] ?? []) as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			$action = $this->publish(transition: $transition);
			if ($action !== null) {
				$actions[] = $action;
			}
		}

		return $actions;
	}//end availableActions()

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
	 * literal string `ok`, not a statusType. So `to` is deliberately NOT added.
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
	 *                              `dispatchedActions` and `version`.
	 *
	 * @throws RuntimeException When the move is refused — a guard said no, the case
	 *                          already moved, the caller may not, a result is missing.
	 * @throws LifecycleSubjectNotFoundException When the case has been deleted.
	 * @throws LifecycleProviderException When the engine could not answer at all.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
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

		try {
			return $this->transitionEngine->execute(
				caseId: $caseId,
				transitionId: $action,
				comment: $this->textOf(data: $data, field: self::COMMENT_INPUT),
				userId: $caller,
				resultTypeId: $this->textOf(data: $data, field: self::CLOSING_INPUT),
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
	 * Map one dossiq transition onto OpenRegister's published action shape.
	 *
	 * Role-hidden transitions need no filtering here: `getAvailableTransitions()`
	 * drops them itself, through `TransitionSpecReader::isRoleHidden()`, before
	 * the caller ever sees them. Re-filtering would be the second derivation
	 * this class exists to avoid.
	 *
	 * @param array<string, mixed> $transition One entry off the engine's answer.
	 *
	 * @return array{action:string,to:string,requires:null,description:string,inputs:list<array{field:string,required:bool}>,label:string,blocked:bool}|null
	 *         Null when the transition names no action, which is unpublishable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function publish(array $transition): ?array {
		$action = (string)($transition['id'] ?? '');
		if ($action === '') {
			return null;
		}

		$toStatus = (string)($transition['toStatus'] ?? '');
		$passed = (($transition['guardsPassed'] ?? true) !== false);

		return [
			'action' => $action,
			'to' => $toStatus,
			// Dossiq has no schema-declared `requires` on a transition: guards
			// are named per transition inside the workflowTemplate and are
			// already evaluated above, so there is no single class name to
			// publish. The failure reasons travel in `description` instead.
			'requires' => null,
			'description' => $this->describe(transition: $transition, passed: $passed),
			'inputs' => $this->inputsFor(toStatus: $toStatus),
			'label' => (string)($transition['label'] ?? ''),
			'blocked' => ($passed === false),
		];
	}//end publish()

	/**
	 * The sentence a client shows under a move.
	 *
	 * A blocked move explains itself with the guards that refused it, joined
	 * in the order they were evaluated — a handler who is told only "blocked"
	 * has to guess which of four guards to satisfy. A move that passed carries
	 * the transition's own description when its workflowTemplate wrote one.
	 *
	 * @param array<string, mixed> $transition One entry off the engine's answer.
	 * @param bool $passed Whether every guard passed.
	 *
	 * @return string The description, empty when there is nothing to say.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function describe(array $transition, bool $passed): string {
		if ($passed === false) {
			$messages = [];
			foreach ((array)($transition['failedGuards'] ?? []) as $guard) {
				if (is_array($guard) === false) {
					continue;
				}

				$message = trim((string)($guard['failureMessage'] ?? ''));
				if ($message !== '') {
					$messages[] = $message;
				}
			}

			if ($messages !== []) {
				return implode(' ', $messages);
			}
		}

		return trim((string)($transition['description'] ?? ''));
	}//end describe()

	/**
	 * The inputs a move must carry before it can be applied.
	 *
	 * Only one exists today: a transition into a final status closes the case,
	 * and `StatusTransitionService::execute()` refuses it without a resultType.
	 * Publishing the input is what turns that refusal into a question the
	 * client asks first.
	 *
	 * @param string $toStatus The statusType UUID the move targets.
	 *
	 * @return list<array{field:string,required:bool}> The declared inputs, empty for an ordinary move.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function inputsFor(string $toStatus): array {
		if ($toStatus === '' || $this->resultWriter->isFinalStatus(statusTypeId: $toStatus) === false) {
			return [];
		}

		return [['field' => self::CLOSING_INPUT, 'required' => true]];
	}//end inputsFor()

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
