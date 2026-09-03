<?php

/**
 * Dossiq voorstel-status guard.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Guard: verifies the case's voorstel has reached one of the allowed statuses.
 *
 * This is the guard the besluitvorming bundles needed and did not have. They
 * guarded the transition out of Parafering on a case field
 * `paraferingCompleet`, which no schema declares and nothing writes, so the
 * transition could never be taken however the guard was spelled. The signal
 * itself was never missing: when the decision app concludes a parafering
 * chain, {@see \OCA\Dossiq\Service\Parafeer\ParaferingConclusionService} writes
 * the outcome onto the VOORSTEL as `geaccordeerd` (or `teruggestuurd` when the
 * chain came back). Reading that status is the difference between asserting
 * parafering completed and asking somebody to tick a box saying it did.
 *
 * The guard fails closed: a case whose parafering chain cannot be located has
 * not completed parafering.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class VoorstelStatusGuard implements GuardEvaluatorInterface {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate the voorstel-status guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration; `allowedStatuses` is required.
	 * @param array<string, mixed> $case The case object.
	 * @param string $userId Current user UID (unused).
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$allowed = $this->allowedStatuses(guardConfig: $guardConfig);
		if ($allowed === []) {
			return new GuardResult(passed: false, failureMessage: 'Voorstel-status guard missing allowedStatuses');
		}

		$status = $this->resolveVoorstelStatus(case: $case);
		if ($status === null) {
			return new GuardResult(
				passed: false,
				failureMessage: 'Geen parafeervoorstel gevonden voor deze zaak',
				details: ['allowedStatuses' => $allowed],
			);
		}

		if (in_array($status, $allowed, true) === true) {
			return new GuardResult(passed: true, details: ['status' => $status]);
		}

		return new GuardResult(
			passed: false,
			failureMessage: sprintf('Parafering nog niet afgerond (voorstel staat op: %s)', $status),
			details: ['status' => $status, 'allowedStatuses' => $allowed],
		);
	}//end evaluate()

	/**
	 * Read the allow-list of voorstel statuses off the guard entry.
	 *
	 * @param array<string, mixed> $guardConfig The guard entry.
	 *
	 * @return array<int, string> The allowed statuses, trimmed and non-empty.
	 */
	private function allowedStatuses(array $guardConfig): array {
		$declared = ($guardConfig['allowedStatuses'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$allowed = [];
		foreach ($declared as $status) {
			$value = trim((string)$status);
			if ($value !== '') {
				$allowed[] = $value;
			}
		}

		return $allowed;
	}//end allowedStatuses()

	/**
	 * Resolve the status of the voorstel belonging to this case.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return string|null The status, or null when no voorstel can be located.
	 */
	private function resolveVoorstelStatus(array $case): ?string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$proposalSchema = $this->settingsService->getConfigValue(key: 'voorstel_schema');
		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($register === '' || $proposalSchema === '' || $caseId === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $proposalSchema,
				filters: ['case' => $caseId, '_limit' => 20],
			);
		} catch (\Throwable $e) {
			$this->logger->error('VoorstelStatusGuard: voorstel lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}

		return $this->latestStatus(rows: $rows);
	}//end resolveVoorstelStatus()

	/**
	 * The status of the last voorstel in the returned set.
	 *
	 * A case may carry more than one voorstel over its life (a returned chain
	 * is followed by a new one). The most recently created row is the chain
	 * this transition is about, so it decides.
	 *
	 * @param array<int, mixed> $rows The voorstel rows.
	 *
	 * @return string|null The status, or null when the set holds none.
	 */
	private function latestStatus(array $rows): ?string {
		$status = null;
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$value = trim((string)($row['status'] ?? ''));
			if ($value !== '') {
				$status = $value;
			}
		}

		return $status;
	}//end latestStatus()
}//end class
