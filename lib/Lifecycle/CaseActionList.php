<?php

/**
 * Dossiq case action list.
 *
 * The moves a case offers, as OpenRegister's lifecycle vocabulary wants them:
 * one entry per transition the engine answered, each with the inputs it needs
 * and a sentence saying why it is open or closed, and then the two rules that
 * block the whole list without hiding it.
 *
 * 🔑 IT DERIVES NOTHING. Every entry comes out of the engine's own answer. A
 * second derivation would eventually offer a move the write refuses, and the
 * user would meet that disagreement as a stage that highlights on hover and
 * then fails.
 *
 * A blocked act stays in the list carrying the reason. An act that vanished
 * would read as a permission problem and send somebody to the rights matrix
 * for an afternoon, which is the opposite of what either rule is saying.
 *
 * Split out of {@see CaseActionProvider}, which was over its complexity
 * ceiling and its coupling ceiling. What is left there is the two calls the
 * interface declares and the one thing only that class can do: say which of
 * OpenRegister's three failures the engine just had.
 *
 * @category Lifecycle
 * @package  OCA\Dossiq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Lifecycle;

use OCA\Dossiq\Service\Cases\ExternalHome;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\UnpaidCaseGate;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;

/**
 * The acts a case offers, and what blocks them.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseActionList {

	/**
	 * The input a closing move must carry.
	 *
	 * `CaseActionProvider::execute()` refuses a transition into a final status
	 * without a resultType, before it mutates anything. Declaring the input
	 * here is what lets a client collect the answer first instead of meeting
	 * the refusal, and it is one constant so the list and the write cannot
	 * disagree about the field's name.
	 */
	public const CLOSING_INPUT = 'resultTypeId';

	/**
	 * The free-form note a move may carry.
	 */
	public const COMMENT_INPUT = 'comment';

	/**
	 * Constructor.
	 *
	 * @param CaseResultWriter  $resultWriter Decides whether a target status closes the case.
	 * @param ExternalHome      $externalHome Whether the work on this case lives in another app.
	 * @param CaseTypeReader    $caseTypes    What the case type declares about paying first.
	 * @param UnpaidCaseGate    $unpaidCases  Whether an unpaid case proceeds.
	 * @param CasePaymentReader $payments     The live payment state of one case.
	 */
	public function __construct(
		private readonly CaseResultWriter $resultWriter,
		private readonly ExternalHome $externalHome,
		private readonly CaseTypeReader $caseTypes,
		private readonly UnpaidCaseGate $unpaidCases,
		private readonly CasePaymentReader $payments,
	) {
	}//end __construct()

	/**
	 * Map every transition the engine answered onto OpenRegister's shape.
	 *
	 * Extracted from `availableActions()` rather than inlined: with the grant
	 * read beside it the method crossed phpmd's complexity thresholds, and a
	 * suppression would have been the wrong answer to a method that had simply
	 * grown two jobs.
	 *
	 * @param array<int, mixed> $transitions The engine's `transitions` list.
	 *
	 * @return list<array<string, mixed>> The publishable actions, in order.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function publishAll(array $transitions): array {
		$actions = [];
		foreach ($transitions as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			$action = $this->publish(transition: $transition);
			if ($action !== null) {
				$actions[] = $action;
			}
		}

		return $actions;
	}//end publishAll()

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
	 * Disable the acts that perform work on a case handled elsewhere.
	 *
	 * @param list<array<string, mixed>> $actions The moves as published.
	 * @param array<string, mixed>       $object  The loaded case payload.
	 *
	 * @return list<array<string, mixed>> The moves, blocked when the work is elsewhere.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function honourExternalHome(array $actions, array $object): array {
		$sentence = $this->externalHome->whereTheWorkIs(case: $object);
		if ($sentence === '') {
			return $actions;
		}

		$disabled = [];
		foreach ($actions as $action) {
			$action['blocked'] = true;
			$action['description'] = $sentence;
			$disabled[] = $action;
		}

		return $disabled;
	}//end honourExternalHome()

	/**
	 * Disable the acts on a case whose type says the money comes first.
	 *
	 * @param list<array<string, mixed>> $actions The moves as published.
	 * @param array<string, mixed>       $object  The loaded case payload.
	 * @param string                     $caseId  The case, for the live payment read.
	 *
	 * @return list<array<string, mixed>> The moves, blocked when payment is owed.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function honourPaymentRule(array $actions, array $object, string $caseId): array {
		$sentence = $this->whyPaymentBlocks(object: $object, caseId: $caseId);
		if ($sentence === '') {
			return $actions;
		}

		$disabled = [];
		foreach ($actions as $action) {
			$action['blocked'] = true;
			$action['description'] = $sentence;
			$disabled[] = $action;
		}

		return $disabled;
	}//end honourPaymentRule()

	/**
	 * The payment rule's verdict on this case, as a sentence or an empty string.
	 *
	 * Public because both halves ask it: the list blocks the acts with it, and
	 * {@see CaseActionProvider::execute()} refuses the posted move with it. A
	 * flag the write path does not check is a suggestion, and the first client
	 * that posts the move anyway hands a case to a handler the gemeente has
	 * not been paid for.
	 *
	 * @param array<string, mixed> $object The loaded case payload.
	 * @param string               $caseId The case, for the live payment read.
	 *
	 * @return string The refusal, '' when the money is not in the way.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function whyPaymentBlocks(array $object, string $caseId): string {
		$rule = $this->caseTypes->paymentRule(caseTypeId: $this->caseTypeIdOf(object: $object));

		// THE RULE IS ASKED BEFORE SHILLINQ IS. Most case types cost nothing,
		// and a case type that does not wait for money must not pay for a
		// cross-app read on every listing of its acts.
		if ($this->unpaidCases->requiresPayment(caseType: $rule) === false) {
			return '';
		}

		// Live, not the stored projection. The projection is an hour old at
		// worst and it exists so the case LIST can be filtered; a refusal is a
		// decision, and a decision made on an hour-old word is a citizen who
		// paid at the counter this morning being told they have not.
		$projection = $this->payments->stateOf(caseId: $caseId);

		return $this->unpaidCases->whyItWaits(case: $projection, caseType: $rule);
	}//end whyPaymentBlocks()

	/**
	 * The case type a case names, however the payload carries it.
	 *
	 * A reference arrives as a plain uuid on a flat read and as an object on an
	 * extended one, and reading only the first shape would quietly answer ''
	 * on every extended payload: the rule would then apply to nothing, with
	 * nothing to see.
	 *
	 * @param array<string, mixed> $object The loaded case payload.
	 *
	 * @return string The case type uuid, '' when the case names none.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	private function caseTypeIdOf(array $object): string {
		$caseType = ($object['caseType'] ?? '');
		if (is_array($caseType) === true) {
			$caseType = ($caseType['id'] ?? ($caseType['@self']['id'] ?? ''));
		}

		return trim((string)$caseType);
	}//end caseTypeIdOf()
}//end class
