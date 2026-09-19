<?php

/**
 * A case waiting for an approval decidiq has not finished walking.
 *
 * A case type names the acts that wait for an approval. This class asks decidiq
 * what became of that approval and turns the answer into one verdict, which
 * both halves of the lifecycle read: the menu draws the act disabled with the
 * reason, and the write path refuses it with the same sentence.
 *
 * 🔴 IT READS THE OUTCOME, IT NEVER COMPUTES ONE (D-1). decidiq holds the
 * approvers, counts the signatures and applies the threshold. A dossiq-side
 * "have enough of them signed" would be a second answer to a question with one
 * correct one, and the two would disagree the first time somebody moved a
 * threshold. So every verdict here comes out of
 * {@see ContractDecisionDelegationService::readDecisionState()} and nothing is
 * derived from a stored approval, because dossiq stores none (ADR-011).
 *
 * 🔴 AN OUTCOME THAT CANNOT BE READ BLOCKS (D-2, ADR-102). The failure this
 * class exists to prevent is a besluit going out because decidiq was briefly
 * unreachable. Unreadable is therefore a refusal carrying 503, not a silent
 * allow, and the sentence says the approval service could not be reached so a
 * handler waits instead of believing the approval came through.
 *
 * 🔑 THE NAMED APPROVERS COME FROM DECIDIQ'S ENVELOPE OR NOT AT ALL. dossiq
 * does not keep a copy. When decidiq names nobody, the case says the approvers
 * could not be read rather than showing an empty list, which would read as
 * "waiting on nobody".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Says whether a gated act may be performed, and what the case is waiting on.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see ApprovalGateDeclaration} is a
 * reader over two arrays with no state and nothing to inject. Making it an
 * instance would add a constructor dependency to hide a `::` behind a `->`.
 */
class ApprovalGate {

	/**
	 * The rule slug an act refused for an outstanding approval carries.
	 *
	 * @var string
	 */
	public const RULE_OUTSTANDING = 'approval-outstanding';

	/**
	 * The rule slug an act refused because decidiq said no carries.
	 *
	 * @var string
	 */
	public const RULE_REJECTED = 'approval-rejected';

	/**
	 * The rule slug an act refused because the outcome could not be read carries.
	 *
	 * @var string
	 */
	public const RULE_UNREADABLE = 'approval-unreadable';

	/**
	 * Every envelope key decidiq may name the outstanding approvers under.
	 *
	 * Three spellings because the walk's envelope is decidiq's and this app
	 * only follows it. Naming one and silently reading nothing for the other
	 * two is how "waiting on nobody" gets printed beside two people's names.
	 *
	 * @var array<int, string>
	 */
	private const APPROVER_KEYS = ['pendingApprovers', 'outstandingApprovers', 'approvers'];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver                  $caseTypes The effective case type, parents included.
	 * @param ContractDecisionDelegationService $decisions The one read seam onto decidiq.
	 * @param LoggerInterface                   $logger    Says why a verdict could not be reached.
	 */
	public function __construct(
		private readonly CaseTypeResolver $caseTypes,
		private readonly ContractDecisionDelegationService $decisions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The verdict on one act of one case.
	 *
	 * NEVER THROWS, so the menu and the write path can share it. The menu draws
	 * a refused act disabled with `sentence`; the write path is
	 * {@see \OCA\Dossiq\Service\Transitions\ApprovalGuard::evaluate()}, which
	 * turns this same verdict into a failing `GuardResult` and is registered in
	 * `GuardRegistry` under the approval-gate key. One decision, two renderings,
	 * and no way for the button and the endpoint to disagree.
	 *
	 * @param array<string, mixed> $case   The stored case payload.
	 * @param string               $act    The act being asked about.
	 * @param string               $userId Who is asking, for decidiq's own read guard.
	 *
	 * @return array{gated: bool, allowed: bool, rule: string, sentence: string, status: int,
	 *               label: string, decisionRef: string, approvers: array<int, string>} The verdict.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public function verdictFor(array $case, string $act, string $userId): array {
		$caseType = $this->caseTypeOf(case: $case);
		if ($caseType === null) {
			// The declaration could not be read, so whether this act is gated
			// is unknown. Unknown is a refusal with a status, not an allow:
			// ADR-102, and the same reading the unreadable outcome gets below.
			return $this->refused(
				rule: self::RULE_UNREADABLE,
				sentence: 'We could not read this case type, so we cannot tell whether this act '
					. 'needs an approval. Try again shortly.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		$gate = ApprovalGateDeclaration::gateFor(caseType: $caseType, act: $act);
		if ($gate === []) {
			return $this->ungated();
		}

		$reference = ApprovalGateDeclaration::referenceFor(case: $case, act: $act);
		if ($reference === '') {
			return $this->refused(
				rule: self::RULE_OUTSTANDING,
				sentence: $gate['label'] . ' has not been asked for yet. Start it in decidiq, '
					. 'then come back to this act.',
				status: RefusedException::STATUS_UNPROCESSABLE,
				label: $gate['label'],
			);
		}

		return $this->verdictFromDecidiq(gate: $gate, reference: $reference, userId: $userId);
	}//end verdictFor()

	/**
	 * What this case is waiting for, and on whom.
	 *
	 * One entry per gate the case type declares that is not granted. A case
	 * waiting on nothing answers with an empty list, which is what lets the
	 * case page show the panel only when there is something to show.
	 *
	 * @param array<string, mixed> $case   The stored case payload.
	 * @param string               $userId Who is asking.
	 *
	 * @return array<int, array{act: string, label: string, sentence: string,
	 *               approvers: array<int, string>, decisionRef: string}> What is outstanding.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public function awaiting(array $case, string $userId): array {
		$caseType = $this->caseTypeOf(case: $case);
		if ($caseType === null) {
			return [];
		}

		$waiting = [];
		foreach (ApprovalGateDeclaration::declaredOn(caseType: $caseType) as $gate) {
			$verdict = $this->verdictFor(case: $case, act: $gate['act'], userId: $userId);
			if ($verdict['allowed'] === true) {
				continue;
			}

			$waiting[] = [
				'act' => $gate['act'],
				'label' => $gate['label'],
				'sentence' => $verdict['sentence'],
				'approvers' => $verdict['approvers'],
				'decisionRef' => $verdict['decisionRef'],
			];
		}//end foreach

		return $waiting;
	}//end awaiting()

	/**
	 * Turn decidiq's answer about one decision into this app's verdict.
	 *
	 * @param array{act: string, decisionType: string, label: string} $gate      The declared gate.
	 * @param string                                                  $reference The decidiq decision id.
	 * @param string                                                  $userId    Who is asking.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private function verdictFromDecidiq(array $gate, string $reference, string $userId): array {
		$read = $this->decisions->readDecisionState(decisionId: $reference, actorId: $userId);
		$state = (string)($read['state'] ?? '');
		$envelope = (array)($read['envelope'] ?? []);
		$approvers = $this->approversIn(envelope: $envelope);

		if ($state === ContractDecisionDelegationService::DECISION_STATE_DECIDED) {
			return $this->decided(
				gate: $gate,
				status: (string)($read['status'] ?? ''),
				reference: $reference,
				approvers: $approvers,
			);
		}

		if ($state === ContractDecisionDelegationService::DECISION_STATE_OPEN) {
			return $this->refused(
				rule: self::RULE_OUTSTANDING,
				sentence: $gate['label'] . ' is still open. ' . $this->onWhom(approvers: $approvers),
				status: RefusedException::STATUS_UNPROCESSABLE,
				label: $gate['label'],
				reference: $reference,
				approvers: $approvers,
			);
		}

		if ($state === ContractDecisionDelegationService::DECISION_STATE_WITHDRAWN) {
			return $this->refused(
				rule: self::RULE_OUTSTANDING,
				sentence: $gate['label'] . ' was withdrawn without an answer. Ask for it again in decidiq.',
				status: RefusedException::STATUS_UNPROCESSABLE,
				label: $gate['label'],
				reference: $reference,
			);
		}

		// GONE, REFUSED and UNREADABLE all end here, and all three block. They
		// differ in what a handler does next, so they keep their own sentences,
		// but none of them is an approval: an act allowed because the approval
		// could not be found would be the fail-open this class exists to stop.
		return $this->cannotRead(gate: $gate, state: $state, reference: $reference);
	}//end verdictFromDecidiq()

	/**
	 * The verdict on a decision decidiq has concluded.
	 *
	 * @param array{act: string, decisionType: string, label: string} $gate      The declared gate.
	 * @param string                                                  $status    decidiq's own status word.
	 * @param string                                                  $reference The decidiq decision id.
	 * @param array<int, string>                                      $approvers Who decidiq named.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private function decided(array $gate, string $status, string $reference, array $approvers): array {
		if ($status === 'approved') {
			return [
				'gated' => true,
				'allowed' => true,
				'rule' => '',
				'sentence' => '',
				'status' => 0,
				'label' => $gate['label'],
				'decisionRef' => $reference,
				'approvers' => $approvers,
			];
		}

		return $this->refused(
			rule: self::RULE_REJECTED,
			sentence: $gate['label'] . ' was refused. This act stays closed while that stands.',
			status: RefusedException::STATUS_REFUSED,
			label: $gate['label'],
			reference: $reference,
			approvers: $approvers,
		);
	}//end decided()

	/**
	 * The verdict when decidiq could not tell us the outcome (D-2, ADR-102).
	 *
	 * @param array{act: string, decisionType: string, label: string} $gate      The declared gate.
	 * @param string                                                  $state     The state decidiq answered.
	 * @param string                                                  $reference The decidiq decision id.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private function cannotRead(array $gate, string $state, string $reference): array {
		$this->logger->warning(
			'Dossiq approval gate: the approval outcome could not be read, so the act stays refused',
			['decisionRef' => $reference, 'state' => $state, 'act' => $gate['act']],
		);

		$sentence = 'We could not reach the approval service, so ' . $gate['label']
			. ' could not be checked. This act stays closed until it answers.';

		if ($state === ContractDecisionDelegationService::DECISION_STATE_REFUSED) {
			$sentence = 'You may not read the outcome of ' . $gate['label']
				. ', so this act stays closed. Ask somebody who may.';
		}

		if ($state === ContractDecisionDelegationService::DECISION_STATE_GONE) {
			$sentence = $gate['label'] . ' no longer exists in decidiq. Ask for it again, '
				. 'then come back to this act.';
		}

		return $this->refused(
			rule: self::RULE_UNREADABLE,
			sentence: $sentence,
			status: RefusedException::STATUS_INDETERMINATE,
			label: $gate['label'],
			reference: $reference,
		);
	}//end cannotRead()

	/**
	 * The sentence naming who the approval waits on.
	 *
	 * 🔑 NOBODY NAMED IS SAID OUT LOUD. An empty list rendered as nothing reads
	 * as "waiting on nobody", which is the one thing it never means.
	 *
	 * @param array<int, string> $approvers Who decidiq named.
	 *
	 * @return string The sentence.
	 */
	private function onWhom(array $approvers): string {
		if ($approvers === []) {
			return 'decidiq did not say who it is waiting on. Open the approval there to see.';
		}

		return 'It is waiting on ' . implode(', ', $approvers) . '.';
	}//end onWhom()

	/**
	 * The approvers decidiq named in its envelope, in order, de-duplicated.
	 *
	 * @param array<string, mixed> $envelope The outcome envelope.
	 *
	 * @return array<int, string> The names, empty when decidiq named none.
	 */
	private function approversIn(array $envelope): array {
		foreach (self::APPROVER_KEYS as $key) {
			$named = $this->namesIn(value: ($envelope[$key] ?? null));
			if ($named !== []) {
				return $named;
			}
		}

		return [];
	}//end approversIn()

	/**
	 * The names in one envelope value, whether it holds strings or rows.
	 *
	 * @param mixed $value The envelope value.
	 *
	 * @return array<int, string> The names.
	 */
	private function namesIn(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$names = [];
		foreach ($value as $entry) {
			$name = '';
			if (is_string($entry) === true) {
				$name = trim($entry);
			}

			if (is_array($entry) === true) {
				$name = trim((string)($entry['name'] ?? ($entry['displayName'] ?? ($entry['userId'] ?? ''))));
			}

			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}//end foreach

		return $names;
	}//end namesIn()

	/**
	 * The verdict for an act no gate applies to.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private function ungated(): array {
		return [
			'gated' => false,
			'allowed' => true,
			'rule' => '',
			'sentence' => '',
			'status' => 0,
			'label' => '',
			'decisionRef' => '',
			'approvers' => [],
		];
	}//end ungated()

	/**
	 * One refusal shape, so no caller has to interpret an absent key.
	 *
	 * @param string             $rule      The rule slug for the `error` field (ADR-050).
	 * @param string             $sentence  The sentence a handler reads.
	 * @param integer            $status    The HTTP status the refusal answers with.
	 * @param string             $label     What the approval is called.
	 * @param string             $reference The decidiq decision id, when there is one.
	 * @param array<int, string> $approvers Who it waits on, when decidiq named them.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private function refused(
		string $rule,
		string $sentence,
		int $status,
		string $label = '',
		string $reference = '',
		array $approvers = [],
	): array {
		return [
			'gated' => true,
			'allowed' => false,
			'rule' => $rule,
			'sentence' => $sentence,
			'status' => $status,
			'label' => $label,
			'decisionRef' => $reference,
			'approvers' => $approvers,
		];
	}//end refused()

	/**
	 * The effective case type of a case, or null when it cannot be read.
	 *
	 * Null is deliberately NOT the same as "declares no gates": the caller
	 * turns it into a refusal with a status, because a case type nobody could
	 * read may well be one that gates this act.
	 *
	 * @param array<string, mixed> $case The stored case payload.
	 *
	 * @return array<string, mixed>|null The effective case type, or null.
	 */
	private function caseTypeOf(array $case): ?array {
		$caseTypeId = $case['caseType'] ?? '';
		if (is_array($caseTypeId) === true) {
			$caseTypeId = ($caseTypeId['id'] ?? ($caseTypeId['uuid'] ?? ''));
		}

		$caseTypeId = trim((string)$caseTypeId);
		if ($caseTypeId === '') {
			// A case with no type declares no gates. That is an ordinary
			// answer and not a failed read, so it is an empty case type and
			// not a null.
			return [];
		}

		try {
			return $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq approval gate: the case type could not be read',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()],
			);

			return null;
		}
	}//end caseTypeOf()
}//end class
