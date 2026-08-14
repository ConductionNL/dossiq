<?php

/**
 * Procest MandaatCheckService.
 *
 * Authorization decision engine for mandates. Answers the question:
 * is user X authorized to take decision Y on case Z right now?
 *
 *   - Resolves the user's active role (primair vs. waarnemer vs. tijdelijk).
 *   - Walks Mandaat rows for the role, filtered by decisionType + caseType +
 *     temporal validity (validFrom <= date <= validUntil).
 *   - Evaluates voorwaarden (plafondCents, subdelegatie, decisionTypes,
 *     caseTypes) against the case properties.
 *   - Returns {authorized, mandaatId|reden}.
 *
 * Money values: integer EUR cents throughout (ADR-031).
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Mandate authorization engine.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class MandaatCheckService {
	use SearchesObjects;

	public const REDEN_NIET_BEVOEGD = 'niet_bevoegd';
	public const REDEN_PLAFOND_OVERSCHREDEN = 'plafond_overschreden';
	public const REDEN_SUBDELEGATIE_NIET_TOEGESTAAN = 'subdelegatie_niet_toegestaan';
	public const REDEN_BELANGENCONFLICT = 'belangenconflict';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param LoggerInterface $logger Logger.
	 * @param ConflictOfInterestService|null $conflictService Optional conflict-of-interest service.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?ConflictOfInterestService $conflictService = null,
	) {
	}//end __construct()

	/**
	 * Decide whether the user is authorized for the (decisionType, case) pair.
	 *
	 * @param string $userId Nextcloud user id.
	 * @param string $decisionType Decision type slug.
	 * @param string $caseId Case id.
	 * @param array<string, mixed> $caseProperties Case properties for condition matching.
	 * @param DateTimeImmutable|null $decisionDate Optional override (defaults to now).
	 *
	 * @return array{authorized:bool, mandaatId?:string, reden?:string|null, conflictReason?:string, failedConditions?:array<int,string>}
	 *
	 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function isAuthorized(
		string $userId,
		string $decisionType,
		string $caseId,
		array $caseProperties = [],
		?DateTimeImmutable $decisionDate = null,
	): array {
		$decisionDate = ($decisionDate ?? new DateTimeImmutable());

		// Belangenconflict check (REQ-MANDAAT-006). NOT optional at runtime: a
		// null conflict service used to skip the check entirely, which is the
		// same fail-open defect class as the check itself returning "no
		// conflict" unconditionally. An unavailable check is indeterminate, and
		// indeterminate denies.
		if ($this->conflictService === null) {
			$this->logger->warning(
				'Procest MandaatCheckService: no conflict-of-interest service bound — denying',
				['userId' => $userId, 'caseId' => $caseId]
			);
			return [
				'authorized' => false,
				'reason' => self::REDEN_BELANGENCONFLICT,
				'conflictReason' => ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE,
			];
		}

		$conflict = $this->conflictService->checkConflict($userId, $caseId, $caseProperties);
		if ($conflict['conflict'] === true) {
			return [
				'authorized' => false,
				'reason' => self::REDEN_BELANGENCONFLICT,
				'conflictReason' => (string)($conflict['reason'] ?? ''),
			];
		}

		$role = $this->resolveUserRole(userId: $userId, date: $decisionDate);
		if ($role === null) {
			return ['authorized' => false, 'reason' => self::REDEN_NIET_BEVOEGD];
		}

		$caseType = (string)($caseProperties['caseType'] ?? '');
		$mandaten = $this->getApplicableMandaten(decisionType: $decisionType, caseType: $caseType, date: $decisionDate);

		$relevant = array_values(
			array_filter(
				$mandaten,
				static fn (array $row): bool => (string)($row['mandateeRole'] ?? '') === (string)$role['roleId']
			)
		);

		if (count($relevant) === 0) {
			return ['authorized' => false, 'reason' => self::REDEN_NIET_BEVOEGD];
		}

		// Pick the first mandaat whose voorwaarden pass; surface the most-specific
		// failure reason when none pass.
		$lastFailure = ['reason' => self::REDEN_NIET_BEVOEGD, 'failedConditions' => []];
		foreach ($relevant as $m) {
			$eval = $this->evaluateConditions(mandate: $m, caseProperties: $caseProperties);
			if ($eval['passed'] === true) {
				return [
					'authorized' => true,
					'mandaatId' => (string)($m['id'] ?? ''),
					'reason' => null,
				];
			}

			$lastFailure = [
				'reason' => $eval['reason'],
				'failedConditions' => $eval['failedConditions'],
			];
		}

		return [
			'authorized' => false,
			'reason' => $lastFailure['reason'],
			'failedConditions' => $lastFailure['failedConditions'],
		];
	}//end isAuthorized()

	/**
	 * Get the applicable mandaten for a decision-type + case-type pair,
	 * active at the given date.
	 *
	 * @param string $decisionType Decision type slug.
	 * @param string $caseType Case type slug (may be empty).
	 * @param DateTimeImmutable|null $date Date (default today).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function getApplicableMandaten(string $decisionType, string $caseType, ?DateTimeImmutable $date = null): array {
		$date = ($date ?? new DateTimeImmutable());
		$dateStr = $date->format('Y-m-d');
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'active']
			);
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if ($this->isRowTemporallyValid(row: $row, dateStr: $dateStr) === false) {
				continue;
			}

			if ($this->matchesTypeTerms(row: $row, decisionType: $decisionType, caseType: $caseType) === false) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end getApplicableMandaten()

	/**
	 * Check whether a row's validFrom/validUntil window covers the given date.
	 *
	 * @param array<string, mixed> $row Row carrying validFrom/validUntil.
	 * @param string $dateStr Date in Y-m-d form.
	 *
	 * @return bool True when the row is temporally valid on that date.
	 */
	private function isRowTemporallyValid(array $row, string $dateStr): bool {
		$validFrom = (string)($row['validFrom'] ?? '1970-01-01');
		$validUntil = (string)($row['validUntil'] ?? '');
		if ($validFrom > $dateStr) {
			return false;
		}

		if ($validUntil !== '' && $validUntil < $dateStr) {
			return false;
		}

		return true;
	}//end isRowTemporallyValid()

	/**
	 * Check a mandaat's decisionTypes/caseTypes voorwaarden against the request.
	 *
	 * An empty list means "no restriction"; an empty case type skips the
	 * case-type filter entirely.
	 *
	 * @param array<string, mixed> $row Mandaat row.
	 * @param string $decisionType Decision type slug.
	 * @param string $caseType Case type slug (may be empty).
	 *
	 * @return bool True when the mandaat applies to the pair.
	 */
	private function matchesTypeTerms(array $row, string $decisionType, string $caseType): bool {
		$voorw = (array)($row['terms'] ?? []);
		$decTypes = (array)($voorw['decisionTypes'] ?? []);
		if (count($decTypes) > 0 && in_array($decisionType, $decTypes, true) === false) {
			return false;
		}

		$caseTypes = (array)($voorw['caseTypes'] ?? []);
		if ($caseType !== '' && count($caseTypes) > 0 && in_array($caseType, $caseTypes, true) === false) {
			return false;
		}

		return true;
	}//end matchesTypeVoorwaarden()

	/**
	 * Applicable mandates for the given user (filtered to their active role).
	 *
	 * Returns the same row shape as {@see getApplicableMandaten()}, augmented
	 * with a `unilateral` flag (true when the user can take the decision
	 * unilaterally, i.e. without escalation). Empty result when the user holds
	 * no active role.
	 *
	 * @param string $userId User id.
	 * @param string $caseType Case type slug (empty = no filter).
	 * @param string $decisionType Decision type slug (empty = list all).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
	 */
	public function getApplicableForUser(string $userId, string $caseType = '', string $decisionType = ''): array {
		$date = new DateTimeImmutable();
		$role = $this->resolveUserRole(userId: $userId, date: $date);
		if ($role === null) {
			return [];
		}

		$roleId = (string)($role['roleId'] ?? '');
		if ($roleId === '') {
			return [];
		}

		$rows = $this->getApplicableMandaten(decisionType: $decisionType, caseType: $caseType, date: $date);

		$out = [];
		foreach ($rows as $row) {
			$mandateRoleId = (string)($row['mandateeRole'] ?? '');
			if ($mandateRoleId !== '' && $mandateRoleId !== $roleId) {
				continue;
			}

			$row['unilateral'] = ($mandateRoleId === $roleId);
			$out[] = $row;
		}

		return $out;
	}//end getApplicableForUser()

	/**
	 * Resolve the user's *primary* active role at the given date.
	 *
	 * Returns an array {rolId, toewijzingType, waarnemerVoor} when found.
	 *
	 * @param string $userId User id.
	 * @param DateTimeImmutable $date Date.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function resolveUserRole(string $userId, DateTimeImmutable $date): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('medewerker_rol_toewijzing_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['userId' => $userId]);
		} catch (\Throwable $e) {
			// Fail closed: log and surface "no role" instead of swallowing.
			$this->logger->error(
				'MandaatCheckService.resolveUserRole lookup failed (fail-closed)',
				['userId' => $userId, 'error' => $e->getMessage()]
			);
			$rows = [];
		}

		$dateStr = $date->format('Y-m-d');
		$active = [];
		foreach ($rows as $row) {
			if ($this->isRowTemporallyValid(row: $row, dateStr: $dateStr) === false) {
				continue;
			}

			$active[] = $row;
		}

		if (count($active) === 0) {
			return null;
		}

		// Sort: primair first, then waarnemer, then tijdelijk.
		$order = ['primair' => 0, 'waarnemer' => 1, 'tijdelijk' => 2];
		usort(
			$active,
			static fn (array $a, array $b): int
				=> ($order[(string)($a['toewijzingType'] ?? 'primair')] ?? 99) <=> ($order[(string)($b['toewijzingType'] ?? 'primair')] ?? 99)
		);

		return $active[0];
	}//end resolveUserRole()

	/**
	 * Evaluate voorwaarden (plafond, subdelegatie) against the case properties.
	 *
	 * @param array<string, mixed> $mandate Mandaat row.
	 * @param array<string, mixed> $caseProperties Case properties (e.g. bedragCents, subdelegatieRequested).
	 *
	 * @return array{passed:bool, reason:string, failedConditions:array<int,string>}
	 *
	 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function evaluateConditions(array $mandate, array $caseProperties): array {
		$voorw = (array)($mandate['terms'] ?? []);
		$failed = [];
		$redenen = [];

		// Plafond check (cents).
		if ($this->plafondExceeded(terms: $voorw, caseProperties: $caseProperties) === true) {
			$failed[] = 'plafond';
			$redenen[] = self::REDEN_PLAFOND_OVERSCHREDEN;
		}

		// Subdelegation check.
		if ($this->subdelegatieDenied(terms: $voorw, caseProperties: $caseProperties) === true) {
			$failed[] = 'subdelegatie';
			$redenen[] = self::REDEN_SUBDELEGATIE_NIET_TOEGESTAAN;
		}

		if (count($failed) === 0) {
			return ['passed' => true, 'reason' => '', 'failedConditions' => []];
		}

		// The most-specific failure wins; plafond is evaluated first and so
		// takes precedence over subdelegatie.
		$effectiveReason = ($redenen[0] ?? self::REDEN_NIET_BEVOEGD);

		return ['passed' => false, 'reason' => $effectiveReason, 'failedConditions' => $failed];
	}//end evaluateConditions()

	/**
	 * Check whether the case amount exceeds the mandaat plafond.
	 *
	 * Both the plafond and the case amount must be present; when either is
	 * absent the plafond is not applicable and the check passes.
	 *
	 * @param array<string, mixed> $terms Mandaat voorwaarden.
	 * @param array<string, mixed> $caseProperties Case properties.
	 *
	 * @return bool True when the plafond is exceeded.
	 */
	private function plafondExceeded(array $terms, array $caseProperties): bool {
		if (isset($terms['plafondCents'], $caseProperties['bedragCents']) === false) {
			return false;
		}

		$plafond = (int)$terms['plafondCents'];
		$amount = (int)$caseProperties['bedragCents'];

		return ($amount > $plafond);
	}//end plafondExceeded()

	/**
	 * Check whether a requested subdelegation is denied by the voorwaarden.
	 *
	 * Only evaluated when the case explicitly requests subdelegation.
	 *
	 * @param array<string, mixed> $terms Mandaat voorwaarden.
	 * @param array<string, mixed> $caseProperties Case properties.
	 *
	 * @return bool True when subdelegation was requested but is not allowed.
	 */
	private function subdelegatieDenied(array $terms, array $caseProperties): bool {
		if (($caseProperties['subdelegatieRequested'] ?? false) !== true) {
			return false;
		}

		return ((bool)($terms['subdelegatie'] ?? false) === false);
	}//end subdelegatieDenied()
}//end class
