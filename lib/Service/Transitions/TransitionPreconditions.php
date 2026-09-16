<?php

/**
 * Withholding beats refusing.
 *
 * A transition that is offered and then refused teaches the handler that the
 * list is unreliable. A transition that is not offered, with the reason
 * readable in its place, teaches them what to do next. The information is the
 * same; only one of the two is usable.
 *
 * So a transition declares what must be settled before it is available, and
 * this class answers two questions about that declaration: is this transition
 * available, and if not, what is in the way. The engine drops the unavailable
 * ones from the list it offers and publishes the reasons beside it.
 *
 * 🔑 EVERY CLOSING STATUS, NOT THE ONE THE CASE TYPE CALLS CLOSED. A case type
 * with Afgehandeld, Ingetrokken and Niet ontvankelijk has three ways out, and a
 * declaration that named one left two doors open. The dependency declares that
 * it blocks `closing`, and the engine resolves that against `statusType.isFinal`
 * at the moment it asks, so a fourth closing status added next year is covered
 * the day it is added.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;

/**
 * Decides whether a transition is available, and says what is in the way.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
class TransitionPreconditions {

	/**
	 * The dependency kinds a transition may declare.
	 *
	 * The first three are the vocabulary `statusType.derivedWhen` already uses,
	 * deliberately: a case type author who has written one has written the
	 * other. `obligationOpen` is the generalisation of the advice request, and
	 * it is the reason this class exists rather than a second consultation
	 * service.
	 *
	 * @var array<int, string>
	 */
	public const DEPENDENCY_KINDS = [
		'obligationOpen',
		'fieldPresent',
		'fieldEquals',
		'documentPresent',
	];

	/**
	 * Constructor.
	 *
	 * @param ObligationService      $obligations The obligations a case is waiting on.
	 * @param ObligationDeclaration  $declaration What an obligation is.
	 * @param DerivedStatusEvaluator $evaluator   The field and document conditions.
	 */
	public function __construct(
		private readonly ObligationService $obligations,
		private readonly ObligationDeclaration $declaration,
		private readonly DerivedStatusEvaluator $evaluator,
	) {
	}//end __construct()

	/**
	 * The dependencies a transition declares, normalised.
	 *
	 * An unknown kind is DROPPED rather than treated as unsettled. A
	 * declaration written for a later version of the vocabulary would otherwise
	 * withhold the transition for ever with a reason nobody can act on, which
	 * is the worst of both: the move is gone and the sentence is useless.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 *
	 * @return array<int, array<string, mixed>> The dependencies worth evaluating.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function declaredFor(array $transition): array {
		$declared = ($transition['requiresSettled'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$dependencies = [];
		foreach ($declared as $dependency) {
			if (is_array($dependency) === false) {
				continue;
			}

			$kind = trim((string)($dependency['kind'] ?? ''));
			if (in_array($kind, self::DEPENDENCY_KINDS, true) === false) {
				continue;
			}

			$dependencies[] = [
				'kind' => $kind,
				'obligationKind' => (string)($dependency['obligationKind'] ?? ''),
				'field' => (string)($dependency['field'] ?? ''),
				'value' => (string)($dependency['value'] ?? ''),
				'documentType' => (string)($dependency['documentType'] ?? ''),
				'label' => (string)($dependency['label'] ?? ''),
			];
		}

		return $dependencies;
	}//end declaredFor()

	/**
	 * Why this transition is withheld, or an empty list when it is available.
	 *
	 * Reasons are sentences about the thing that is open, in the author's own
	 * words where they wrote any, because "the advice request is still open" is
	 * actionable and "requiresSettled failed" is not.
	 *
	 * @param array<string, mixed> $transition  The transition definition.
	 * @param array<string, mixed> $case        The case.
	 * @param bool                 $toIsClosing Whether the destination closes the case.
	 *
	 * @return array<int, string> The reasons, in declaration order.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function withheldReasons(array $transition, array $case, bool $toIsClosing): array {
		$dependencies = $this->declaredFor(transition: $transition);
		if ($dependencies === []) {
			return [];
		}

		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		$toStatus = (string)($transition['toStatus'] ?? '');

		$reasons = [];
		foreach ($dependencies as $dependency) {
			$reason = $this->reasonFor(
				dependency: $dependency,
				case: $case,
				caseId: $caseId,
				toStatus: $toStatus,
				toIsClosing: $toIsClosing,
			);
			if ($reason !== '') {
				$reasons[] = $reason;
			}
		}

		return $reasons;
	}//end withheldReasons()

	/**
	 * Why one dependency is in the way, or the empty string when it is settled.
	 *
	 * @param array<string, mixed> $dependency  The normalised dependency.
	 * @param array<string, mixed> $case        The case.
	 * @param string               $caseId      The case id.
	 * @param string               $toStatus    The destination status.
	 * @param bool                 $toIsClosing Whether the destination closes the case.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	private function reasonFor(
		array $dependency,
		array $case,
		string $caseId,
		string $toStatus,
		bool $toIsClosing,
	): string {
		if ((string)($dependency['kind'] ?? '') === 'obligationOpen') {
			return $this->obligationReason(
				dependency: $dependency,
				caseId: $caseId,
				toStatus: $toStatus,
				toIsClosing: $toIsClosing,
			);
		}

		// The field and document kinds are the SAME evaluation a derived status
		// runs, asked of one condition instead of a list. One evaluator rather
		// than two is what stops "the file is complete" meaning two things on
		// the same case.
		$verdict = $this->evaluator->evaluate(
			statusType: ['derivedWhen' => [$dependency]],
			case: $case,
		);
		if ($verdict['satisfied'] === true) {
			return '';
		}

		return (string)($verdict['unmet'][0] ?? '');
	}//end reasonFor()

	/**
	 * Why an open obligation is in the way.
	 *
	 * @param array<string, mixed> $dependency  The normalised dependency.
	 * @param string               $caseId      The case id.
	 * @param string               $toStatus    The destination status.
	 * @param bool                 $toIsClosing Whether the destination closes the case.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	private function obligationReason(
		array $dependency,
		string $caseId,
		string $toStatus,
		bool $toIsClosing,
	): string {
		if ($caseId === '') {
			return '';
		}

		$wanted = trim((string)($dependency['obligationKind'] ?? ''));
		foreach ($this->obligations->blocking(
			caseId: $caseId,
			statusId: $toStatus,
			isClosing: $toIsClosing,
		) as $obligation) {
			if ($wanted !== '' && trim((string)($obligation['kind'] ?? '')) !== $wanted) {
				continue;
			}

			$label = trim((string)($dependency['label'] ?? ''));
			if ($label !== '') {
				return $label;
			}

			$title = trim((string)($obligation['title'] ?? ''));
			if ($title !== '') {
				return $title;
			}

			return trim((string)($obligation['kind'] ?? ''));
		}

		return '';
	}//end obligationReason()

	/**
	 * The obligation states this class treats as still blocking.
	 *
	 * Exposed so a caller reporting on a case reads the same answer the engine
	 * withholds on, rather than a second reading of the same rows.
	 *
	 * @param array<string, mixed> $obligation The obligation row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function isBlocking(array $obligation): bool {
		return $this->declaration->isBlocking(obligation: $obligation);
	}//end isBlocking()
}//end class
