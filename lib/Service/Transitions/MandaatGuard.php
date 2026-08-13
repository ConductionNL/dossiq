<?php

/**
 * Procest mandaatGuard guard evaluator.
 *
 * Blocks the "Besluit genomen" transition of a mandaatbesluit until the signing
 * official's authority is confirmed against the mandaatregister. When the
 * register is unreachable or unconfigured the guard does NOT silently pass: it
 * fails with a message that prompts the handler to confirm authority manually
 * (a manual confirmation set on the case as `mandaatHandmatigBevestigd = true`
 * satisfies the guard and is auditable).
 *
 * Guard config shape: `{type: 'mandaatGuard'}`.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCA\Procest\Service\MandaatValidationService;

/**
 * Guard: verifies signing-official mandate against the mandaatregister.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class MandaatGuard implements GuardEvaluatorInterface {
	/**
	 * Constructor.
	 *
	 * @param MandaatValidationService $validationService The mandaatregister validator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MandaatValidationService $validationService,
	) {
	}//end __construct()

	/**
	 * Evaluate the mandaat guard.
	 *
	 * @param array<string, mixed> $guardConfig The guard configuration block.
	 * @param array<string, mixed> $case The case object.
	 * @param string $userId The current user UID.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		// An explicit, auditable manual confirmation satisfies the guard.
		if (($case['mandaatHandmatigBevestigd'] ?? false) === true) {
			return new GuardResult(passed: true, details: ['mandaat' => 'handmatig_bevestigd']);
		}

		$caseId = (string)($case['id'] ?? $case['uuid'] ?? '');
		$signingId = (string)($case['signatory'] ?? $userId);

		$result = $this->validationService->validate(caseId: $caseId, signingUserId: $signingId);

		if (($result['valid'] ?? false) === true) {
			return new GuardResult(passed: true, details: ['mandaat' => 'bevestigd']);
		}

		return new GuardResult(
			passed: false,
			failureMessage: (string)($result['message'] ?? 'Onvoldoende mandaat voor dit besluit.'),
			details: [
				'requiresManualConfirmation' => (bool)($result['requiresManualConfirmation'] ?? false),
				'registerLink' => (string)($result['registerLink'] ?? ''),
			],
		);
	}//end evaluate()
}//end class
