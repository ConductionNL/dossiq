<?php

/**
 * Dossiq MandaatCheckService.
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
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\MandaatCheckServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Mandate authorization engine.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class MandaatCheckService implements MandaatCheckServiceInterface {
	use SearchesObjects;

	public const REDEN_NIET_BEVOEGD = 'non_competent';
	public const REDEN_PLAFOND_OVERSCHREDEN = 'ceiling_exceeded';
	public const REDEN_SUBDELEGATIE_NIET_TOEGESTAAN = 'subdelegatie_non_permitted';
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
	 * @return array{authorized:bool, mandaatId?:string, reden?:string|null, conflictReason?:string, failedConditions?:array<int,string>,
	 *               relationCheck?:string, relationCheckReason?:string}
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 * @spec openspec/specs/quality-gates/spec.md
	 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
	 */
	public function isAuthorized(
		string $userId,
		string $decisionType,
		string $caseId,
		array $caseProperties = [],
		?DateTimeImmutable $decisionDate = null,
	): array {
		$check = new MandateCheck();
		$check->userId = $userId;
		$check->decisionType = $decisionType;
		$check->caseId = $caseId;
		$check->caseProperties = $caseProperties;
		$check->decisionDate = ($decisionDate ?? new DateTimeImmutable());

		$conflictRefusal = $this->conflictRefusal(check: $check);
		if ($conflictRefusal !== null) {
			return $conflictRefusal;
		}

		return $this->mandateVerdict(check: $check);
	}//end isAuthorized()

	/**
	 * The belangenconflict refusal (REQ-MANDAAT-006), or null when there is no conflict.
	 *
	 * NOT optional at runtime: a null conflict service used to skip the check
	 * entirely, which is the same fail-open defect class as the check itself
	 * returning "no conflict" unconditionally. An unavailable check is
	 * indeterminate, and indeterminate denies.
	 *
	 * @param MandateCheck $check The check; it then carries the relation step's answer.
	 *
	 * @return array<string, mixed>|null
	 */
	private function conflictRefusal(MandateCheck $check): ?array {
		if ($this->conflictService === null) {
			return $this->unboundConflictRefusal(check: $check);
		}

		$conflict = $this->conflictService->checkConflict($check->userId, $check->caseId, $check->caseProperties);
		if ($conflict['conflict'] === true) {
			return [
				'authorized' => false,
				'reason' => self::REDEN_BELANGENCONFLICT,
				'conflictReason' => (string)($conflict['reason'] ?? ''),
			];
		}

		// Whether the family relation step ran travels with every verdict
		// below, so a decision made without it does not read like one made
		// with it.
		$check->relation = array_intersect_key($conflict, ['relationCheck' => true, 'relationCheckReason' => true]);

		return null;
	}//end conflictRefusal()

	/**
	 * The refusal when no conflict-of-interest service is bound: indeterminate denies.
	 *
	 * @param MandateCheck $check The check.
	 *
	 * @return array<string, mixed>
	 */
	private function unboundConflictRefusal(MandateCheck $check): array {
		$this->logger->warning(
			'Dossiq MandaatCheckService: no conflict-of-interest service bound — denying',
			['userId' => $check->userId, 'caseId' => $check->caseId]
		);

		return [
			'authorized' => false,
			'reason' => self::REDEN_BELANGENCONFLICT,
			'conflictReason' => ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE,
		];
	}//end unboundConflictRefusal()

	/**
	 * The verdict of the user's role against the mandates that apply.
	 *
	 * @param MandateCheck $check The check, past the conflict step.
	 *
	 * @return array<string, mixed>
	 */
	private function mandateVerdict(MandateCheck $check): array {
		$role = $this->resolveUserRole(userId: $check->userId, date: $check->decisionDate);
		if ($role === null) {
			return (['authorized' => false, 'reason' => self::REDEN_NIET_BEVOEGD] + $check->relation);
		}

		$caseType = (string)($check->caseProperties['caseType'] ?? '');
		$mandaten = $this->getApplicableMandaten(decisionType: $check->decisionType, caseType: $caseType, date: $check->decisionDate);

		$relevant = array_values(
			array_filter(
				$mandaten,
				static fn (array $row): bool => (string)($row['mandateeRole'] ?? '') === (string)$role['roleId']
			)
		);

		if (count($relevant) === 0) {
			return (['authorized' => false, 'reason' => self::REDEN_NIET_BEVOEGD] + $check->relation);
		}

		return $this->firstPassingMandate(check: $check, relevant: $relevant);
	}//end mandateVerdict()

	/**
	 * Pick the first mandaat whose voorwaarden pass; surface the most-specific
	 * failure reason when none pass.
	 *
	 * @param MandateCheck                     $check    The check.
	 * @param array<int, array<string, mixed>> $relevant The applicable mandates of the user's role.
	 *
	 * @return array<string, mixed>
	 */
	private function firstPassingMandate(MandateCheck $check, array $relevant): array {
		$lastFailure = ['reason' => self::REDEN_NIET_BEVOEGD, 'failedConditions' => []];
		foreach ($relevant as $m) {
			$eval = $this->evaluateConditions(mandate: $m, caseProperties: $check->caseProperties);
			if ($eval['passed'] === true) {
				return ([
					'authorized' => true,
					'mandaatId' => (string)($m['id'] ?? ''),
					'reason' => null,
				] + $check->relation);
			}

			$lastFailure = [
				'reason' => $eval['reason'],
				'failedConditions' => $eval['failedConditions'],
			];
		}

		return ([
			'authorized' => false,
			'reason' => $lastFailure['reason'],
			'failedConditions' => $lastFailure['failedConditions'],
		] + $check->relation);
	}//end firstPassingMandate()

	/**
	 * Get the applicable mandaten for a decision-type + case-type pair,
	 * active at the given date.
	 *
	 * @param string $decisionType Decision type slug.
	 * @param string $caseType Case type slug (may be empty).
	 * @param DateTimeImmutable|null $date Date (default today).
	 *
	 * @return array<int, array<string, mixed>> The mandates, or an empty list when none apply.
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function getApplicableMandaten(string $decisionType, string $caseType, ?DateTimeImmutable $date = null): array {
		$out = [];
		foreach ($this->activeRows(date: ($date ?? new DateTimeImmutable()), context: ['decisionType' => $decisionType, 'caseType' => $caseType]) as $row) {
			if ($this->matchesTypeTerms(row: $row, decisionType: $decisionType, caseType: $caseType) === false) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end getApplicableMandaten()

	/**
	 * The active mandates whose window covers the date, whatever they apply to.
	 *
	 * Read with the caller's own rights.
	 *
	 * @param DateTimeImmutable    $date    The date.
	 * @param array<string, mixed> $context What the caller asked about, for the log.
	 *
	 * @return array<int, array<string, mixed>> The mandates.
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function activeRows(DateTimeImmutable $date, array $context): array {
		$dateStr = $date->format('Y-m-d');
		$store = $this->storeFor(schemaKey: 'mandaat_schema');
		if ($store === null) {
			return [];
		}

		return array_values(
			array_filter(
				$this->readActiveMandates(store: $store, context: $context),
				fn (array $row): bool => $this->isRowTemporallyValid(row: $row, dateStr: $dateStr)
			)
		);
	}//end activeRows()

	/**
	 * Whether an organisation holds a mandate that is active on the date.
	 *
	 * Active means status active and a validity window covering the date. The
	 * register is read across organisations, because the question is asked
	 * about a tenant from the admin's tenant page; only the yes or no leaves
	 * this method. An unreadable register answers no.
	 *
	 * @param string            $organisation The organisation UUID.
	 * @param DateTimeImmutable $date         The date.
	 *
	 * @return bool True when the organisation has an active mandate.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
	 */
	public function hasActiveMandateFor(string $organisation, DateTimeImmutable $date): bool {
		if (trim($organisation) === '') {
			return false;
		}

		$store = $this->storeFor(schemaKey: 'mandaat_schema');
		if ($store === null) {
			return false;
		}

		[$objectService, $register, $schema] = $store;
		$rows = $this->activeMandateRows(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			organisation: $organisation,
		);
		if ($rows === null) {
			return false;
		}

		$dateStr = $date->format('Y-m-d');

		return $this->anyActiveFor(rows: $rows, organisation: $organisation, dateStr: $dateStr);
	}//end hasActiveMandateFor()

	/**
	 * The active mandate rows across organisations.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The mandate schema id.
	 * @param string $organisation  The organisation UUID, for the log.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the register could not be read.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
	 */
	private function activeMandateRows(object $objectService, string $register, string $schema, string $organisation): ?array {
		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'active']
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq MandaatCheckService: mandate register could not be read for an organisation',
				['organisation' => $organisation, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $rows;
	}//end activeMandateRows()

	/**
	 * Whether any row is an active mandate of the organisation, valid on the date.
	 *
	 * @param array<int, array<string, mixed>> $rows         The mandate rows.
	 * @param string                           $organisation The organisation UUID.
	 * @param string                           $dateStr      The date, `Y-m-d`.
	 *
	 * @return bool True when one is.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
	 */
	private function anyActiveFor(array $rows, string $organisation, string $dateStr): bool {
		foreach ($rows as $row) {
			if ((string)($row['status'] ?? '') === 'active'
				&& self::organisationOf(row: $row) === $organisation
				&& $this->isRowTemporallyValid(row: $row, dateStr: $dateStr) === true
			) {
				return true;
			}
		}

		return false;
	}//end anyActiveFor()

	/**
	 * The organisation that owns a row: its `@self` organisation, else its own field.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The organisation UUID, or ''.
	 */
	private static function organisationOf(array $row): string {
		$organisation = ($row['@self']['organisation'] ?? ($row['organisation'] ?? ''));
		if (is_array($organisation) === true) {
			$organisation = ($organisation['uuid'] ?? ($organisation['id'] ?? ''));
		}

		if (is_scalar($organisation) === false) {
			return '';
		}

		return (string)$organisation;
	}//end organisationOf()

	/**
	 * The object service, the register and one schema, or null when any is missing.
	 *
	 * @param string $schemaKey The config key of the schema.
	 *
	 * @return array{0: object, 1: string, 2: string}|null
	 */
	private function storeFor(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end storeFor()

	/**
	 * The active mandate rows; an unreadable register refuses as indeterminate.
	 *
	 * @param array{0: object, 1: string, 2: string} $store   The object service, register and mandate schema.
	 * @param array<string, mixed>                   $context What is being decided, for the log.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RefusedException When the mandate register cannot be read.
	 */
	private function readActiveMandates(array $store, array $context): array {
		[$objectService, $register, $schema] = $store;
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'active']
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq MandaatCheckService: mandate register could not be read',
				$context + ['exception' => $e->getMessage()]
			);
			// An empty list here made isAuthorized() answer "niet bevoegd",
			// which is a statement about the user. An unreadable register is
			// not one.
			throw new RefusedException(
				rule: 'mandaat-register-unreadable',
				sentence: 'The mandate register could not be read, so this decision cannot be authorised right now.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try
	}//end readActiveMandates()

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
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-08-user-ui/tasks.md
	 * @spec openspec/specs/quality-gates/spec.md
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
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function resolveUserRole(string $userId, DateTimeImmutable $date): ?array {
		$rows = $this->roleAssignmentsOf(userId: $userId);
		if ($rows === null) {
			return null;
		}

		$active = $this->validOn(rows: $rows, dateStr: $date->format('Y-m-d'));
		if (count($active) === 0) {
			return null;
		}

		return $this->firstByAllocation(active: $active);
	}//end resolveUserRole()

	/**
	 * The user's role assignments; a failed read is logged and answers none (fail closed).
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array<string, mixed>>|null The assignments, or null without a store.
	 */
	private function roleAssignmentsOf(string $userId): ?array {
		$store = $this->storeFor(schemaKey: 'medewerker_rol_toewijzing_schema');
		if ($store === null) {
			return null;
		}

		[$objectService, $register, $schema] = $store;
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

		return $rows;
	}//end roleAssignmentsOf()

	/**
	 * The rows valid on the day.
	 *
	 * @param array<int, array<string, mixed>> $rows    The rows.
	 * @param string                           $dateStr The day (Y-m-d).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function validOn(array $rows, string $dateStr): array {
		$active = [];
		foreach ($rows as $row) {
			if ($this->isRowTemporallyValid(row: $row, dateStr: $dateStr) === false) {
				continue;
			}

			$active[] = $row;
		}

		return $active;
	}//end validOn()

	/**
	 * The assignment that counts: primair first, then waarnemer, then tijdelijk.
	 *
	 * @param array<int, array<string, mixed>> $active The valid assignments, at least one.
	 *
	 * @return array<string, mixed>
	 */
	private function firstByAllocation(array $active): array {
		$order = ['primair' => 0, 'observer' => 1, 'tijdelijk' => 2];
		usort(
			$active,
			static fn (array $a, array $b): int
				=> ($order[(string)($a['allocationType'] ?? 'primair')] ?? 99) <=> ($order[(string)($b['allocationType'] ?? 'primair')] ?? 99)
		);

		return $active[0];
	}//end firstByAllocation()

	/**
	 * Evaluate voorwaarden (plafond, subdelegatie) against the case properties.
	 *
	 * @param array<string, mixed> $mandate Mandaat row.
	 * @param array<string, mixed> $caseProperties Case properties (e.g. bedragCents, subdelegatieRequested).
	 *
	 * @return array{passed:bool, reason:string, failedConditions:array<int,string>}
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
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
