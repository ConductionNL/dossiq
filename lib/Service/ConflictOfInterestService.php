<?php

/**
 * Procest ConflictOfInterestService.
 *
 * Belangenconflict detection for mandate decisions:
 *
 *   - Automatic detection: extract applicant BSN from the case, walk a
 *     relationship lookup (BRP integration — pluggable here, stub by
 *     default) for the userId's BSN.
 *   - Manual registration: caseworker can register a conflict reason
 *     against a case; subsequent isAuthorized() checks see it and
 *     return belangenconflict.
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
 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Belangenconflict detection.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class ConflictOfInterestService {

	/**
	 * Reason returned when the check cannot be performed because the case
	 * worker's identity cannot be resolved.
	 *
	 * This is a CONFLICT (it blocks), not a pass: an unresolvable
	 * conflict-of-interest check must never report "no conflict".
	 */
	public const REASON_IDENTITY_INDETERMINATE = 'identiteit_onbepaald';

	/**
	 * Manually-registered conflicts keyed by zaakId.
	 *
	 * @var array<string, string>
	 */
	private array $registered = [];

	/**
	 * In-memory relationship index for tests; production wires a BRP
	 * adapter via setRelationshipLookup().
	 *
	 * @var callable|null
	 */
	private $relationshipLookup = null;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param BrpHaalCentraalAdapterInterface|null $brpAdapter Optional BRP Haal
	 *                                                         Centraal adapter
	 *                                                         for relationship
	 *                                                         enrichment.
	 *                                                         Dormant by
	 *                                                         default.
	 * @param MedewerkerIdentityResolverInterface|null $identityResolver Optional server-side
	 *                                                                   case-worker identity
	 *                                                                   resolver. Dormant by
	 *                                                                   default; an unbound
	 *                                                                   resolver makes the
	 *                                                                   check indeterminate,
	 *                                                                   which BLOCKS.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?BrpHaalCentraalAdapterInterface $brpAdapter = null,
		private readonly ?MedewerkerIdentityResolverInterface $identityResolver = null,
	) {
	}//end __construct()

	/**
	 * Hash a BSN for comparison / logging.
	 *
	 * AVG art. 9: BSNs are compared and logged as SHA-256 hashes, never raw.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return string The SHA-256 hash.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private static function hashBsn(string $bsn): string {
		return hash('sha256', $bsn);
	}//end hashBsn()

	/**
	 * Resolve the case worker's BSN server-side.
	 *
	 * Returns null when no resolver is bound or the resolver cannot establish
	 * the identity — the caller then fails closed. The value is never logged.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string|null The worker's BSN, or null when indeterminate.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function resolveEmployeeBsn(string $userId): ?string {
		if ($this->identityResolver === null || $userId === '') {
			return null;
		}

		try {
			return $this->identityResolver->bsnFor($userId);
		} catch (\Throwable $e) {
			// Never let a resolver failure read as "no conflict".
			$this->logger->warning(
				'Medewerker identity resolution failed — treating as indeterminate',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveMedewerkerBsn()

	/**
	 * Configure the relationship-lookup callable.
	 *
	 * The callable signature is `(userBsn, applicantBsn): string|null`
	 * returning a relationship label (e.g. "spouse", "parent") or null.
	 *
	 * @param callable $lookup Lookup callable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
	 */
	public function setRelationshipLookup(callable $lookup): void {
		$this->relationshipLookup = $lookup;
	}//end setRelationshipLookup()

	/**
	 * Check whether the user has a belangenconflict with the case applicant.
	 *
	 * FAILS CLOSED. Previously this gated on `$caseProperties['userBsn']`, which
	 * no caller ever populated, so it returned "no conflict" unconditionally on
	 * every live call — the check was decorative. Worse, `$caseProperties` comes
	 * from the request body via `MandaatMatrixController::probe()`, so the
	 * identity it gated on was attacker-controlled.
	 *
	 * Now:
	 *   - `userBsn` in `$caseProperties` is IGNORED. The case worker's identity
	 *     is resolved server-side via MedewerkerIdentityResolverInterface.
	 *   - `applicantBsn` is authoritative only because the controller re-derives
	 *     it server-side from the case object and strips client identity keys.
	 *   - Applicant known + worker unresolvable => INDETERMINATE => conflict,
	 *     never "no conflict".
	 *   - No applicant identity => no conflict (nothing to compare against).
	 *   - BSNs are compared as SHA-256 hashes and never logged (AVG art. 9).
	 *
	 * @param string $userId User id.
	 * @param string $caseId Case id.
	 * @param array<string, mixed> $caseProperties Case properties. Only
	 *                                             `applicantBsn` is consulted,
	 *                                             and only when the caller has
	 *                                             sourced it server-side.
	 *
	 * @return array{conflict:bool, reason?:string}
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function checkConflict(string $userId, string $caseId, array $caseProperties = []): array {
		$this->logger->debug('Conflict-of-interest probe', ['userId' => $userId, 'caseId' => $caseId]);

		// Manual registration trumps automatic detection.
		if (isset($this->registered[$caseId]) === true) {
			return ['conflict' => true, 'reason' => $this->registered[$caseId]];
		}

		// The applicant identity is authoritative ONLY because the caller
		// (MandaatMatrixController::probe) re-derives it server-side from the
		// case object and strips any client-supplied identity keys first.
		$applicantBsn = (string)($caseProperties['applicantBsn'] ?? '');
		if ($applicantBsn === '') {
			// No natural-person applicant on this case: there is nobody to have
			// a conflict WITH, so "no conflict" is a sound answer rather than a
			// fail-open.
			return ['conflict' => false];
		}

		// The case-worker identity is resolved SERVER-SIDE. It is deliberately
		// NOT read from $caseProperties: that array originates from the request
		// body, and an authorization input supplied by the requester is not an
		// authorization input — a caller would simply omit `userBsn` to force
		// "no conflict" (the bug this replaces).
		$userBsn = $this->resolveEmployeeBsn(userId: $userId);
		if ($userBsn === null || $userBsn === '') {
			// INDETERMINATE: the applicant is known but we cannot establish who
			// the case worker is, so we cannot answer the question. A conflict
			// check that cannot run MUST NOT report "no conflict" — fail closed.
			$this->logger->warning(
				'Belangenconflict check is indeterminate — blocking',
				['userId' => $userId, 'caseId' => $caseId]
			);
			return ['conflict' => true, 'reason' => self::REASON_IDENTITY_INDETERMINATE];
		}

		// Constant-time comparison of hashes — never of raw BSNs.
		if (hash_equals(self::hashBsn(bsn: $userBsn), self::hashBsn(bsn: $applicantBsn)) === true) {
			return ['conflict' => true, 'reason' => 'self'];
		}

		return $this->detectRelationConflict(userBsn: $userBsn, applicantBsn: $applicantBsn, caseId: $caseId);
	}//end checkConflict()

	/**
	 * Detect a family/relationship conflict between worker and applicant.
	 *
	 * Both identities are already resolved server-side by the caller.
	 *
	 * @param string $userBsn The case worker's BSN (in memory only).
	 * @param string $applicantBsn The applicant's BSN (in memory only).
	 * @param string $caseId Case id (audit correlation).
	 *
	 * @return array{conflict:bool, reason?:string}
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function detectRelationConflict(string $userBsn, string $applicantBsn, string $caseId): array {
		if ($this->relationshipLookup !== null) {
			try {
				$relation = ($this->relationshipLookup)($userBsn, $applicantBsn);
			} catch (\Throwable $e) {
				// A failed relationship lookup is indeterminate, not "no
				// conflict": we asked whether a relation exists and got no
				// answer. Fail closed.
				$this->logger->warning('Relationship lookup failed — blocking', ['error' => $e->getMessage()]);
				return ['conflict' => true, 'reason' => self::REASON_IDENTITY_INDETERMINATE];
			}

			if (is_string($relation) === true && $relation !== '') {
				return ['conflict' => true, 'reason' => $relation];
			}
		}

		// BRP adapter fallback — dormant by default; an active binding looks
		// up the user's relationship to the applicant via Haal Centraal
		// `relaties` envelope and short-circuits with `belangenconflict`.
		$brpRelation = $this->lookupRelationViaBrp(userBsn: $userBsn, applicantBsn: $applicantBsn, caseId: $caseId);
		if ($brpRelation !== null && $brpRelation !== '') {
			return ['conflict' => true, 'reason' => $brpRelation];
		}

		return ['conflict' => false];
	}//end detectRelationConflict()

	/**
	 * Consult the BRP / Haal Centraal adapter for a relationship label.
	 *
	 * The adapter ships dormant by default; the LOOKUP_DEFERRED outcome
	 * yields null so the conflict check stays open. An active binding
	 * returns the user's relation (e.g. `partner`, `parent`, `child`)
	 * via the persoon envelope's `relaties` block.
	 *
	 * Per AVG / WBP article 9 the BSN values themselves are NEVER logged
	 * — the dormant adapter redacts them, and this caller never forwards
	 * them to the structured logger.
	 *
	 * @param string $userBsn User BSN.
	 * @param string $applicantBsn Applicant BSN.
	 * @param string $caseId Case id (audit correlation).
	 *
	 * @return string|null Relationship label, or null when unknown / dormant.
	 *
	 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
	 */
	private function lookupRelationViaBrp(string $userBsn, string $applicantBsn, string $caseId): ?string {
		if ($this->brpAdapter === null) {
			return null;
		}

		try {
			$result = $this->brpAdapter->lookup(
				$userBsn,
				[
					'lookupReason' => 'belangenconflict-detection',
					'caseId' => $caseId,
					'comparisonBsnHash' => substr(hash('sha256', $applicantBsn), 0, 16),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BRP relationship lookup failed',
				['caseId' => $caseId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($result->lookupStatus !== 'FOUND') {
			return null;
		}

		$relations = (array)($result->persoon['relaties'] ?? []);
		foreach ($relations as $relation) {
			if (is_array($relation) === false) {
				continue;
			}

			$relatedBsn = (string)($relation['citizenServiceNumber'] ?? '');
			if ($relatedBsn === '' || $relatedBsn !== $applicantBsn) {
				continue;
			}

			$label = (string)($relation['relatie'] ?? $relation['type'] ?? '');
			if ($label !== '') {
				return $label;
			}
		}

		return null;
	}//end lookupRelationViaBrp()

	/**
	 * Manually register a belangenconflict on a case.
	 *
	 * @param string $caseId Case id.
	 * @param string $reason Reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
	 */
	public function registerConflict(string $caseId, string $reason): void {
		$this->registered[$caseId] = $reason;
	}//end registerConflict()

	/**
	 * Clear a manually-registered conflict.
	 *
	 * @param string $caseId Case id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
	 */
	public function clearConflict(string $caseId): void {
		unset($this->registered[$caseId]);
	}//end clearConflict()
}//end class
