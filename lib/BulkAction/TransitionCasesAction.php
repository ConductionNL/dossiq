<?php

/**
 * Dossiq bulk action: move many cases to another status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category BulkAction
 * @package  OCA\Dossiq\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BulkAction;

use InvalidArgumentException;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
use Throwable;

/**
 * One status transition, over every case the job selected.
 *
 * dossiq declares what happens to ONE case and writes no loop: the job record,
 * the rehearsal, the progress, the per-row outcome, the cancel and the retry
 * belong to OpenRegister (ADR-022, D-1). `StatusTransitionService` stays the
 * only write path for `case.status`, here as everywhere else.
 *
 * The rehearsal and the commit are the same method with `$commit` flipped,
 * which is what makes the dry run a rehearsal rather than a promise: the
 * rehearsal asks the engine's read-only `getAvailableTransitions()`, which
 * evaluates every guard without writing.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class TransitionCasesAction implements BulkActionInterface {

	use ReadsCaseObject;

	/**
	 * The action id every caller names.
	 *
	 * @var string
	 */
	public const ID = 'dossiq:transition-cases';

	/**
	 * Constructor.
	 *
	 * @param StatusTransitionService $engine The only write path for case.status.
	 * @param IL10N                   $l10n   Localisation, for the label an operator reads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StatusTransitionService $engine,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The action id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return $this->l10n->t('Move to another status');
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Runs one status transition over every selected case, through the transition engine.');
	}//end getDescription()

	/**
	 * A transition carries its own comment and needs no separate reason.
	 *
	 * @return bool False.
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * A transition's guards are per case, so the engine evaluates none up front.
	 *
	 * @return array<int, string> No guards.
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * A transition id is required, and nothing else is read.
	 *
	 * @param array<string, mixed> $parameters The job parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no transition is named.
	 */
	public function validateParameters(array $parameters): void {
		if (trim((string)($parameters['transitionId'] ?? '')) === '') {
			throw new InvalidArgumentException('transitionId is required');
		}
	}//end validateParameters()

	/**
	 * Move one case, or say what moving it would do.
	 *
	 * @param ObjectEntity         $object     The case the job is walking.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One executor for the
	 * rehearsal and the commit is the design property of D-1.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The engine reads the
	 * acting user from the session; the job runs as that user.
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$caseId = $this->caseId(object: $object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		$transitionId = trim((string)($parameters['transitionId'] ?? ''));

		if ($commit === false) {
			return $this->rehearse(caseId: $caseId, transitionId: $transitionId);
		}

		$comment = null;
		if (isset($parameters['comment']) === true) {
			$comment = (string)$parameters['comment'];
		}

		try {
			$this->engine->execute(caseId: $caseId, transitionId: $transitionId, comment: $comment);

			return BulkActionResult::applied();
		} catch (GuardFailedException $e) {
			return BulkActionResult::refused(rule: $this->firstGuardMessage(guards: $e->getFailedGuards()));
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}//end try
	}//end apply()

	/**
	 * Ask the engine what this transition would do, writing nothing.
	 *
	 * @param string $caseId       The case uuid.
	 * @param string $transitionId The transition asked for.
	 *
	 * @return BulkActionResult What would happen.
	 */
	private function rehearse(string $caseId, string $transitionId): BulkActionResult {
		try {
			$available = $this->engine->getAvailableTransitions(caseId: $caseId);
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}

		foreach (($available['transitions'] ?? []) as $transition) {
			if ((string)($transition['id'] ?? '') !== $transitionId) {
				continue;
			}

			if (($transition['guardsPassed'] ?? false) === true) {
				return BulkActionResult::applied();
			}

			return BulkActionResult::refused(rule: $this->firstGuardMessage(guards: ($transition['failedGuards'] ?? [])));
		}

		return BulkActionResult::skipped(reason: 'transition_not_available');
	}//end rehearse()

	/**
	 * The first failed guard's message, which is what the skip list shows.
	 *
	 * A guard that fails without saying why would render as an empty row, so
	 * the fallback names the guard family rather than nothing.
	 *
	 * @param array<int, array<string, mixed>> $guards The failed guards.
	 *
	 * @return string The reason.
	 */
	private function firstGuardMessage(array $guards): string {
		foreach ($guards as $guard) {
			$message = trim((string)($guard['message'] ?? ''));
			if ($message !== '') {
				return $message;
			}
		}

		return 'guard_failed';
	}//end firstGuardMessage()
}//end class
