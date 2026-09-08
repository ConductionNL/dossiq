<?php

/**
 * Dossiq Guard Registry.
 *
 * Strategy-pattern registry mapping guard `type` strings to the corresponding
 * GuardEvaluatorInterface implementations. Built-in evaluators are injected
 * via DI; downstream specs MAY register additional types via
 * `registerEvaluator()` (e.g. an integration hook in `Application::register()`).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

use Psr\Log\LoggerInterface;

/**
 * Registry of guard evaluators keyed by guard type.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T06
 */
class GuardRegistry {

	/**
	 * The guard type every transition is checked against, template or not.
	 *
	 * The list it reads is authored on the STATUS, so a transition that never
	 * names it is still subject to it — see StatusChecklistGuard.
	 */
	public const STATUS_CHECKLIST = 'statusChecklist';

	/**
	 * Registered evaluators keyed by guard type.
	 *
	 * @var array<string, GuardEvaluatorInterface>
	 */
	private array $evaluators = [];

	/**
	 * Constructor.
	 *
	 * @param ChecklistGuard $checklist Built-in checklist evaluator
	 * @param RequiredFieldGuard $requiredField Built-in required-field evaluator
	 * @param RequiredDocumentGuard $requiredDocument Built-in required-document evaluator
	 * @param RoleGuard $roleGuard Built-in role evaluator
	 * @param MandaatGuard $mandateGuard Mandaatregister authority evaluator
	 * @param StatusChecklistGuard $statusChecklist Required-items-of-the-current-status evaluator
	 * @param LoggerInterface $logger Logger for unknown guard types
	 */
	public function __construct(
		ChecklistGuard $checklist,
		RequiredFieldGuard $requiredField,
		RequiredDocumentGuard $requiredDocument,
		RoleGuard $roleGuard,
		MandaatGuard $mandateGuard,
		StatusChecklistGuard $statusChecklist,
		private readonly LoggerInterface $logger,
	) {
		$this->evaluators = [
			'checklist' => $checklist,
			'requiredField' => $requiredField,
			'requiredDocument' => $requiredDocument,
			'roleGuard' => $roleGuard,
			'mandaatGuard' => $mandateGuard,
			// Registered like any other type, but declared by no template: the
			// engine appends it to every transition's guard list itself.
			self::STATUS_CHECKLIST => $statusChecklist,
		];
	}//end __construct()

	/**
	 * Register an additional evaluator (DI extension point).
	 *
	 * @param string $type Guard type identifier
	 * @param GuardEvaluatorInterface $evaluator Evaluator implementation
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function registerEvaluator(string $type, GuardEvaluatorInterface $evaluator): void {
		$this->evaluators[$type] = $evaluator;
	}//end registerEvaluator()

	/**
	 * Evaluate every guard in declaration order and collect snapshots.
	 *
	 * @param array<int, array<string, mixed>> $guards List of guard configs
	 * @param array<string, mixed> $case The case
	 * @param string $userId Current user UID
	 *
	 * @return array<int, array{type: string, passed: bool, failureMessage: ?string, details: array<string, mixed>}>
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluateAll(array $guards, array $case, string $userId): array {
		$results = [];
		foreach ($guards as $guard) {
			$type = (string)($guard['type'] ?? '');
			if ($type === '') {
				continue;
			}

			$this->warnOnNestedConfig(type: $type, guard: $guard);

			if (isset($this->evaluators[$type]) === false) {
				$this->logger->warning('Unknown guard type', ['type' => $type]);
				$results[] = [
					'type' => $type,
					'passed' => false,
					'failureMessage' => 'Onbekende guard',
					'details' => ['unknown' => true],
				];
				continue;
			}

			$result = $this->evaluators[$type]->evaluate(guardConfig: $guard, case: $case, userId: $userId);

			$details = $result->details;
			if ($details === []) {
				$details = null;
			}

			$results[] = [
				'type' => $type,
				'passed' => $result->passed,
				'failureMessage' => $result->failureMessage,
				// EMPTY DETAILS MUST BE NULL, NOT AN EMPTY OBJECT. This
				// snapshot is persisted as `statusRecord.evaluatedGuards`,
				// whose `details` is declared `type: object`. OpenRegister
				// refuses an empty one and says so precisely: "Property
				// 'evaluatedGuards.0.details' expects object but got empty
				// ({}). For non-required object properties, set this to null
				// to clear the field."
				//
				// `GuardResult::$details` defaults to `[]`, and a guard that
				// simply PASSES has nothing to report, so the common case is
				// the empty one. That went unnoticed until the status
				// checklist guard was appended to every transition: before
				// that, a transition with no declared guards produced no
				// snapshot entries at all, so there was nothing to reject.
				// Afterwards every transition had at least entry 0, and
				// `StatusTransitionController::execute()` catches Throwable
				// and answers 500, so the failure surfaced as a dialog that
				// never closes rather than as a validation message.
				'details' => $details,
			];
		}//end foreach

		return $results;
	}//end evaluateAll()

	/**
	 * Report a guard that declares its parameters at a position nothing reads.
	 *
	 * Every evaluator reads its parameters from the top level of the guard
	 * entry. A guard written as `{"type": "requiredField", "config": {"field":
	 * "x"}}` therefore resolves no field at all, and the evaluator answers
	 * with its empty-configuration failure — which reads as "the case is not
	 * ready" rather than "this guard was never wired up". Three shipped
	 * besluitvorming bundles carried exactly that, and a case could not leave
	 * Parafering on any fresh install. Saying so out loud is what makes the
	 * next one findable.
	 *
	 * @param string $type The guard type.
	 * @param array<string, mixed> $guard The guard entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function warnOnNestedConfig(string $type, array $guard): void {
		$config = ($guard['config'] ?? null);
		if (is_array($config) === false || $config === []) {
			return;
		}

		$this->logger->warning(
			'Dossiq: a guard declares its parameters under `config`, a position the transition engine '
			. 'never reads; the guard will evaluate as unconfigured. Declare them at the top level of '
			. 'the guard entry.',
			['type' => $type, 'keys' => array_keys($config)],
		);
	}//end warnOnNestedConfig()

	/**
	 * Check if all guards in the result set passed.
	 *
	 * @param array<int, array{passed: bool}> $results The evaluateAll() output
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function allPassed(array $results): bool {
		foreach ($results as $result) {
			if ($result['passed'] !== true) {
				return false;
			}
		}

		return true;
	}//end allPassed()
}//end class
