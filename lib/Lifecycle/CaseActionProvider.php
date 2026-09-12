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
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes a case's available status transitions on OpenRegister's vocabulary.
 *
 * 🔑 IT DERIVES NOTHING. Every entry comes out of
 * {@see StatusTransitionService::getAvailableTransitions()} — the same reader
 * the POST path validates against in `execute()`. A second derivation here
 * would eventually offer a move the write refuses, and the user would meet
 * that disagreement as a stage that highlights on hover and then fails.
 *
 * Read-only, as the interface requires: no mutation, nothing derived written
 * back. The call is a GET a client may repeat at will.
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
