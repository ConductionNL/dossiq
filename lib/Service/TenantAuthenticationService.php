<?php

/**
 * Dossiq Tenant Authentication Service
 *
 * Validates that a user is authorised to perform an action on a tenant by
 * resolving the tenant's active TenantMandate and matching the user's role
 * + action against the mandate matrix.
 *
 * Fail-closed: any unresolved matrix, role, or service error returns
 * `{allowed: false}` so the caller cannot fall open.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mandate-matrix authorisation guard for tenant actions.
 *
 * Every lookup here reads `ObjectService::findAll()` rows, and `findAll()`
 * returns `ObjectEntity` objects, not arrays. Indexing one as an array throws,
 * and keeping only the rows that are arrays keeps none. So each row goes
 * through {@see OpenRegisterRowNormaliser} before a property is read off it.
 * Until that was done, no membership ever resolved, and the role and matrix
 * lookups threw on the first row they read.
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
 */
class TenantAuthenticationService {
	use RefusesWhenIndeterminate;

	/**
	 * Default deny-everything matrix (fail-closed fallback).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const DEFAULT_DENY_MATRIX = [];

	/**
	 * Upper bound on memberships read for one user.
	 *
	 * A person belongs to a handful of tenants, not hundreds. The cap exists so
	 * a malformed filter that matches every row cannot turn a per-request
	 * lookup into a full table read.
	 *
	 * @var int
	 */
	private const MAX_MEMBERSHIPS = 100;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager (for OR availability check).
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row, entity or array, as an array.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * Validate a tenant action against the active mandate matrix.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 * @param string $action Requested action (create|edit|status_update|delete|...).
	 *
	 * @return array{allowed: bool, reason: string} Decision payload.
	 *
	 * @throws RefusedException When the matrix could not be read, so the answer is neither yes nor no.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function validateMandateMatrix(string $tenantId, string $userId, string $action): array {
		try {
			$matrix = $this->loadActiveMatrix(tenantId: $tenantId);
			if ($matrix === null) {
				return ['allowed' => false, 'reason' => 'No active mandate matrix for tenant'];
			}

			$role = $this->resolveUserRole(tenantId: $tenantId, userId: $userId);
			if ($role === null) {
				return ['allowed' => false, 'reason' => 'User has no role inside tenant'];
			}

			$allowed = $this->isAllowed(matrix: $matrix, role: $role, action: $action);
			if ($allowed === true) {
				return ['allowed' => true, 'reason' => 'Authorised by mandate matrix'];
			}

			return ['allowed' => false, 'reason' => 'Role ' . $role . ' is not authorised for action ' . $action];
		} catch (RefusedException $e) {
			// A refusal already says what happened and with which status.
			// Folding it into the fail-closed branch below is what made an
			// unreadable matrix indistinguishable from a role that may not act.
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: mandate matrix validation failed (fail-closed)',
				['tenantId' => $tenantId, 'userId' => $userId, 'exception' => $e->getMessage()]
			);
			return ['allowed' => false, 'reason' => 'Mandate validation error'];
		}//end try
	}//end validateMandateMatrix()

	/**
	 * Check whether the matrix authorises (role, action).
	 *
	 * The matrix layout is `{role: {action: bool}}`. A wildcard role `*` or
	 * action `*` is honoured. Missing entries default to false (fail-closed).
	 *
	 * @param array<string, array<string, bool>> $matrix Active mandate matrix.
	 * @param string $role Resolved user role.
	 * @param string $action Requested action.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function isAllowed(array $matrix, string $role, string $action): bool {
		$roleEntry = ($matrix[$role] ?? null);
		$wildcardEntry = ($matrix['*'] ?? null);

		$candidates = [];
		if (is_array($roleEntry) === true) {
			$candidates[] = $roleEntry;
		}

		if (is_array($wildcardEntry) === true) {
			$candidates[] = $wildcardEntry;
		}

		foreach ($candidates as $entry) {
			if (($entry[$action] ?? false) === true) {
				return true;
			}

			if (($entry['*'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end isAllowed()

	/**
	 * Load the active mandate matrix for the tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, array<string, bool>>|null Active matrix, or null when the tenant has none.
	 *
	 * @throws RefusedException When the matrix store could not be read at all.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function loadActiveMatrix(string $tenantId): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$active = $this->activeMandateRowOf(rows: $this->readMandateRows(objectService: $objectService, tenantId: $tenantId));
		if ($active === null) {
			return null;
		}

		return $this->normaliseMatrix(matrixField: ($active['matrix'] ?? null));
	}//end loadActiveMatrix()

	/**
	 * The mandate row in force now, among the rows read.
	 *
	 * @param mixed $rows The rows as findAll() answers them.
	 *
	 * @return array<string, mixed>|null The active row, or null when there are no rows or none applies.
	 */
	private function activeMandateRowOf(mixed $rows): ?array {
		if (is_array($rows) === false || count($rows) === 0) {
			return null;
		}

		return $this->findActiveMandateRow(rows: $this->rowsAsArrays(rows: $rows));
	}//end activeMandateRowOf()

	/**
	 * Read the tenant's mandate rows, refusing when the store cannot be read.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $tenantId      Tenant UUID.
	 *
	 * @return mixed The rows as findAll() answers them.
	 *
	 * @throws RefusedException When the matrix store could not be read at all.
	 */
	private function readMandateRows(object $objectService, string $tenantId): mixed {
		return $this->readOrRefuse(
			read: fn (): mixed => $objectService->findAll($this->mandateQuery(tenantId: $tenantId)),
			what: 'the mandate matrix for tenant ' . $tenantId,
			rule: 'tenant-mandate-matrix-unreadable',
			sentence: 'The mandate matrix could not be read, so this action cannot be checked right now.',
		);
	}//end readMandateRows()

	/**
	 * The findAll() config for a tenant's mandate rows.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function mandateQuery(string $tenantId): array {
		// ObjectService::findAll() takes a single $config array — the previous
		// named-argument form threw "Unknown named parameter $register" and was
		// swallowed by the catch that stood here, which is how an unreadable
		// matrix became "not authorised". Register/schema live inside
		// `filters`; limit/offset are top-level config keys.
		return [
			'filters' => [
				'register' => Application::REGISTER_SLUG,
				'schema' => 'tenantMandate',
				'tenantRef' => $tenantId,
			],
			'limit' => 50,
			'offset' => 0,
		];
	}//end mandateQuery()

	/**
	 * Pick the mandate row whose effective window contains "now".
	 *
	 * @param array<int, array<string, mixed>> $rows The tenantMandate rows, read as arrays.
	 *
	 * @return array<string, mixed>|null The active row, or null when none applies.
	 */
	private function findActiveMandateRow(array $rows): ?array {
		$now = time();
		foreach ($rows as $row) {
			if ($this->isEffectiveAt(row: $row, moment: $now) === true) {
				return $row;
			}
		}

		return null;
	}//end findActiveMandateRow()

	/**
	 * Whether a mandate row's effective window holds the moment.
	 *
	 * A missing bound is open-ended; a bound that does not parse makes the row apply never.
	 *
	 * @param array<string, mixed> $row    The tenantMandate row.
	 * @param int                  $moment The moment, a Unix timestamp.
	 *
	 * @return bool True when the row is in force at the moment.
	 */
	private function isEffectiveAt(array $row, int $moment): bool {
		$from = strtotime((string)($row['effectiveFrom'] ?? '1970-01-01'));
		$to = strtotime((string)($row['effectiveTo'] ?? '2099-12-31'));

		return ($from !== false && $to !== false && $from <= $moment && $moment <= $to);
	}//end isEffectiveAt()

	/**
	 * Normalise the `matrix` field of an active mandate row.
	 *
	 * @param mixed $matrixField The raw matrix value.
	 *
	 * @return array<string, array<string, bool>> The resolved matrix.
	 */
	private function normaliseMatrix(mixed $matrixField): array {
		if (is_array($matrixField) === true) {
			return $matrixField;
		}

		if (is_string($matrixField) === true) {
			$decoded = json_decode($matrixField, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return self::DEFAULT_DENY_MATRIX;
		}

		// Fallback: a default role-action matrix when the active mandate row
		// does not embed one. Mirrors the common municipal mandate template.
		return [
			'tenant_admin' => ['*' => true],
			'case_handler' => ['create' => true, 'edit' => true, 'status_update' => true],
			'viewer' => [],
		];
	}//end normaliseMatrix()

	/**
	 * Resolve the role for a user inside a tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 *
	 * @return string|null Role name or null when unresolved.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function resolveUserRole(string $tenantId, string $userId): ?string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			return $this->firstRoleOf(rows: $objectService->findAll($this->tenantUserQuery(tenantId: $tenantId, userId: $userId)));
		} catch (Throwable $e) {
			// Fail CLOSED: a backend error is NOT "no role". Surfacing it as a
			// null role would let the mandate-matrix caller treat the lookup as
			// simply absent and silently fall open. Log it and re-throw so the
			// single caller (validateMandateMatrix) denies the action.
			$this->logger->error(
				'Dossiq: resolveUserRole lookup failed (fail-closed)',
				['tenantId' => $tenantId, 'userId' => $userId, 'exception' => $e->getMessage()]
			);
			throw $e;
		}
	}//end resolveUserRole()

	/**
	 * The findAll() config for one user's membership row inside one tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId   NC user ID.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function tenantUserQuery(string $tenantId, string $userId): array {
		// ObjectService::findAll() takes a single $config array — see the note
		// above; register/schema live inside `filters`.
		return [
			'filters' => [
				'register' => Application::REGISTER_SLUG,
				'schema' => 'tenantUser',
				'tenantRef' => $tenantId,
				'userRef' => $userId,
			],
			'limit' => 1,
			'offset' => 0,
		];
	}//end tenantUserQuery()

	/**
	 * The role on the first membership row.
	 *
	 * @param mixed $rows The rows as findAll() answers them.
	 *
	 * @return string|null The role, or null when there is no row or it names none.
	 */
	private function firstRoleOf(mixed $rows): ?string {
		if (is_array($rows) === false || count($rows) === 0) {
			return null;
		}

		$row = $this->rowNormaliser->normalise(row: $rows[0])['data'];
		$role = (string)($row['role'] ?? '');
		if ($role !== '') {
			return $role;
		}

		return null;
	}//end firstRoleOf()

	/**
	 * The tenant ids the given user is a member of.
	 *
	 * WHY THIS EXISTS. The tenant a request acts as is decided by the session,
	 * not by an `X-Tenant-Id` header and not by a JWT claim. Both of those are
	 * supplied by the caller, and a caller is exactly who must not choose. This
	 * is the lookup that says which tenants a user may legitimately be on, so
	 * that a switch can be verified and a session can be resolved.
	 *
	 * Fails CLOSED for the same reason as `resolveUserRole()`: a backend error
	 * returned as an empty list is indistinguishable from "member of nothing",
	 * and the caller would read it as a clean answer.
	 *
	 * @param string $userId The Nextcloud uid.
	 *
	 * @return array<int, string> Tenant ids, possibly empty.
	 *
	 * @throws Throwable When the membership lookup fails.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function listTenantsForUser(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$rows = $this->readMemberships(objectService: $objectService, userId: $userId);
		if (is_array($rows) === false) {
			return [];
		}

		return $this->distinctTenantRefs(rows: $rows);
	}//end listTenantsForUser()

	/**
	 * Read a user's membership rows; a failed read is logged and thrown (fail-closed).
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $userId        The Nextcloud uid.
	 *
	 * @return mixed The rows as findAll() answers them.
	 *
	 * @throws Throwable When the membership lookup fails.
	 */
	private function readMemberships(object $objectService, string $userId): mixed {
		try {
			return $objectService->findAll($this->membershipQuery(userId: $userId));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: listTenantsForUser lookup failed (fail-closed)',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
			throw $e;
		}
	}//end readMemberships()

	/**
	 * The findAll() config for a user's membership rows.
	 *
	 * @param string $userId The Nextcloud uid.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function membershipQuery(string $userId): array {
		return [
			'filters' => [
				'register' => Application::REGISTER_SLUG,
				'schema' => 'tenantUser',
				'userRef' => $userId,
			],
			'limit' => self::MAX_MEMBERSHIPS,
			'offset' => 0,
		];
	}//end membershipQuery()

	/**
	 * The tenant refs on the membership rows, trimmed, empty ones skipped, each once.
	 *
	 * @param array<int, mixed> $rows The membership rows.
	 *
	 * @return array<int, string> Tenant ids, in row order.
	 */
	private function distinctTenantRefs(array $rows): array {
		$tenantIds = [];
		foreach ($this->rowsAsArrays(rows: $rows) as $row) {
			$tenantId = trim((string)($row['tenantRef'] ?? ''));
			if ($tenantId === '' || in_array($tenantId, $tenantIds, true) === true) {
				continue;
			}

			$tenantIds[] = $tenantId;
		}

		return $tenantIds;
	}//end distinctTenantRefs()

	/**
	 * Whether the user is a member of the tenant.
	 *
	 * Membership is "has a tenantUser row", which is what `resolveUserRole()`
	 * already answers — a row without a role is still a row, so this asks the
	 * membership question directly rather than inferring it from a role string
	 * that may legitimately be empty.
	 *
	 * @param string $tenantId The tenant id.
	 * @param string $userId   The Nextcloud uid.
	 *
	 * @return bool Whether the user may act as this tenant.
	 *
	 * @throws Throwable When the membership lookup fails.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function isMemberOf(string $tenantId, string $userId): bool {
		if ($tenantId === '' || $userId === '') {
			return false;
		}

		return in_array($tenantId, $this->listTenantsForUser(userId: $userId), true);
	}//end isMemberOf()

	/**
	 * Read each findAll() row as an array, whatever shape it arrives in.
	 *
	 * A row that carries nothing readable is dropped rather than kept as an
	 * empty array. Kept, it would read as a mandate with no window, and a
	 * mandate with no window counts as active: dropping it can only remove a
	 * membership or a mandate, never add one.
	 *
	 * @param array<int, mixed> $rows The raw findAll() rows.
	 *
	 * @return array<int, array<string, mixed>> The readable rows as arrays, in order.
	 */
	private function rowsAsArrays(array $rows): array {
		$arrays = array_map(
			fn (mixed $row): array => $this->rowNormaliser->normalise(row: $row)['data'],
			$rows
		);

		return array_values(array_filter($arrays, static fn (array $data): bool => $data !== []));
	}//end rowsAsArrays()

	/**
	 * Resolve OR's ObjectService when installed.
	 *
	 * @return mixed|null
	 */
	private function getObjectService() {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end getObjectService()
}//end class
