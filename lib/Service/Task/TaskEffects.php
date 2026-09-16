<?php

/**
 * What completing a task does, read off the task and run through the registry.
 *
 * The effect belongs to the task definition rather than to the handler
 * remembering. A case type declares that finishing "verstuur de beschikking"
 * sends the letter, and finishing "verwerk de aanvulling" resumes the term
 * that was paused to ask for it, and neither depends on somebody doing the
 * next thing by hand.
 *
 * 🔴 AN EFFECT THAT DID NOT RUN AND SAID NOTHING IS THE FAILURE THIS AVOIDS.
 * So there are two moments and both are loud. At publish,
 * {@see TaskDeclarationValidator} refuses a task naming a handler the registry
 * does not have. At completion, {@see unresolved()} is asked BEFORE the task
 * is completed, and a declaration that has stopped resolving since it was
 * published refuses the completion rather than completing without the effect,
 * per ADR-102.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
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

namespace OCA\Dossiq\Service\Task;

use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, checks and runs the effects a task declared.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
class TaskEffects {

	/**
	 * Where a task carries the effects dossiq declared on it.
	 *
	 * `metadata` is the engine's own room for an owning app's data, and
	 * `dossiq` is dossiq's corner of it. Writing effects into a top-level
	 * column would need a column the engine does not have and dossiq does not
	 * own.
	 *
	 * @var string
	 */
	public const METADATA_KEY = 'dossiq';

	/**
	 * Constructor.
	 *
	 * @param ActionHandlerRegistry $handlers The handlers an effect names.
	 * @param LoggerInterface       $logger   The logger.
	 */
	public function __construct(
		private readonly ActionHandlerRegistry $handlers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The effects one task declares, in declared order.
	 *
	 * @param array<string, mixed> $task The task, as the engine answers it.
	 *
	 * @return array<int, array<string, mixed>> The effects; empty when it declares none.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function declaredOn(array $task): array {
		$metadata = ($task['metadata'] ?? []);
		if (is_array($metadata) === false) {
			return [];
		}

		$mine = ($metadata[self::METADATA_KEY] ?? []);
		if (is_array($mine) === false) {
			return [];
		}

		$effects = ($mine['effects'] ?? []);
		if (is_array($effects) === false) {
			return [];
		}

		$declared = [];
		foreach ($effects as $effect) {
			if (is_array($effect) === false) {
				continue;
			}

			$type = trim((string)($effect['type'] ?? ''));
			if ($type === '') {
				continue;
			}

			$effect['type'] = $type;
			$declared[] = $effect;
		}

		return $declared;
	}//end declaredOn()

	/**
	 * The declared effects no handler answers to, by name.
	 *
	 * Asked before a completion, never after: after it there is nothing left
	 * to refuse, and a task completed without its effect looks exactly like
	 * one completed with it.
	 *
	 * @param array<int, array<string, mixed>> $effects The declared effects.
	 *
	 * @return array<int, string> The unresolvable types, in declared order.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function unresolved(array $effects): array {
		$missing = [];
		foreach ($effects as $effect) {
			$type = trim((string)($effect['type'] ?? ''));
			if ($type === '' || $this->handlers->getHandler(type: $type) !== null) {
				continue;
			}

			if (in_array($type, $missing, true) === false) {
				$missing[] = $type;
			}
		}

		return $missing;
	}//end unresolved()

	/**
	 * Run the effects of a completed task, in declared order.
	 *
	 * Per EFFECT, not per task: one effect that fails does not stop the
	 * others, because a declaration that sends a letter and resumes a term
	 * should still resume the term when the mail server is down. Each outcome
	 * is returned and the failures are logged with the task that declared
	 * them.
	 *
	 * @param array<int, array<string, mixed>> $effects The declared effects.
	 * @param array<string, mixed>             $case    The case the task is on.
	 * @param array<string, mixed>             $context What ran them, for the handlers.
	 *
	 * @return array<int, array{type: string, ran: bool, error: string}> One result per effect.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function run(array $effects, array $case, array $context): array {
		$results = [];
		foreach ($effects as $effect) {
			$results[] = $this->runOne(effect: $effect, case: $case, context: $context);
		}

		return $results;
	}//end run()

	/**
	 * Run one effect and say what happened.
	 *
	 * @param array<string, mixed> $effect  The effect.
	 * @param array<string, mixed> $case    The case.
	 * @param array<string, mixed> $context The dispatch context.
	 *
	 * @return array{type: string, ran: bool, error: string} The outcome.
	 */
	private function runOne(array $effect, array $case, array $context): array {
		$type = trim((string)($effect['type'] ?? ''));
		$handler = $this->handlers->getHandler(type: $type);
		if ($handler === null) {
			// Reached only when a handler disappeared between the pre-check
			// and the run, which is a deployment changing under a request.
			// Loud, because the completion is already written.
			$this->logger->error(
				'Dossiq: a completed task declared an effect no handler answers to',
				['effect' => $type, 'case' => ($context['caseId'] ?? ''), 'task' => ($context['taskId'] ?? '')]
			);

			return ['type' => $type, 'ran' => false, 'error' => 'unresolvable_effect'];
		}

		try {
			$result = $handler->handle($effect, $case, $context);
			if ($result->succeeded === true) {
				return ['type' => $type, 'ran' => true, 'error' => ''];
			}

			$this->logger->warning(
				'Dossiq: a task effect refused',
				['effect' => $type, 'error' => $result->error, 'task' => ($context['taskId'] ?? '')]
			);

			return ['type' => $type, 'ran' => false, 'error' => (string)$result->error];
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a task effect failed',
				['effect' => $type, 'exception' => $e->getMessage(), 'task' => ($context['taskId'] ?? '')]
			);

			return ['type' => $type, 'ran' => false, 'error' => $e->getMessage()];
		}//end try
	}//end runOne()
}//end class
