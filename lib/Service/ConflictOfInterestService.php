<?php

/**
 * Dossiq ConflictOfInterestService.
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
 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Registry\PersonRelationReader;
use Psr\Log\LoggerInterface;

/**
 * Belangenconflict detection.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class ConflictOfInterestService {

	/**
	 * `relationCheck` when the family relation step ran and found no relation
	 * to the applicant.
	 */
	public const RELATION_CHECK_DONE = 'checked';

	/**
	 * `relationCheck` when the family relation step could not run on this
	 * instance. The answer then also carries `relationCheckReason`.
	 */
	public const RELATION_CHECK_NOT_AVAILABLE = 'not-available';

	/**
	 * Reason returned when the family relation read is switched on and gave
	 * no answer. A CONFLICT (it blocks), for the same reason as below.
	 */
	public const REASON_RELATION_INDETERMINATE = 'relatie_onbepaald';

	/**
	 * The reasons that mean the relation read is not there to be asked:
	 * integriq is absent or too old, or the municipality has not switched
	 * relation numbers on for its BRP source. Any other reason means the
	 * read was asked and did not answer.
	 *
	 * @var array<int, string>
	 */
	private const RELATION_READ_ABSENT = [
		PersonRelationReader::REASON_NO_INTEGRIQ,
		PersonRelationReader::REASON_NO_READER,
		'relations-not-enabled',
		'no-source',
	];

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
	 * @param PersonRelationReader|null $relationReader Reads the case worker's partner,
	 *                                                  parents and children through
	 *                                                  integriq. Unbound, the relation
	 *                                                  step reports "not available".
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
		private readonly ?PersonRelationReader $relationReader = null,
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
	 * @return array{conflict:bool, reason?:string, relationCheck?:string, relationCheckReason?:string}
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
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
	 * @return array{conflict:bool, reason?:string, relationCheck?:string, relationCheckReason?:string}
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

		return $this->relationFromRegistry(userBsn: $userBsn, applicantBsn: $applicantBsn, caseId: $caseId);
	}//end detectRelationConflict()

	/**
	 * Ask the population register, through integriq, whether the applicant
	 * is the case worker's partner, parent or child.
	 *
	 * Three outcomes, and none of them is silent:
	 *
	 *   - The read is not there to be asked (integriq absent, or the
	 *     municipality has not switched relation numbers on for its BRP
	 *     source): no conflict is claimed, and the answer carries
	 *     `relationCheck: not-available` with the reason, so a decision made
	 *     without the step does not read like one made with it.
	 *   - The read is switched on and gives no answer: INDETERMINATE. We
	 *     asked whether a relation exists and were not told. Fail closed.
	 *   - The read answers: a relation to the applicant is a conflict named by
	 *     the relation; no such relation is `relationCheck: checked`.
	 *
	 * Per AVG art. 9 no BSN is logged, and numbers are compared as hashes.
	 *
	 * @param string $userBsn The case worker's BSN (in memory only).
	 * @param string $applicantBsn The applicant's BSN (in memory only).
	 * @param string $caseId Case id (audit correlation).
	 *
	 * @return array{conflict:bool, reason?:string, relationCheck?:string, relationCheckReason?:string}
	 *
	 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
	 */
	private function relationFromRegistry(string $userBsn, string $applicantBsn, string $caseId): array {
		$answer = ['available' => false, 'reason' => PersonRelationReader::REASON_NO_INTEGRIQ, 'relations' => []];
		if ($this->relationReader !== null) {
			try {
				$answer = $this->relationReader->read($userBsn);
			} catch (\Throwable $e) {
				$answer = ['available' => false, 'reason' => PersonRelationReader::REASON_UNREACHABLE, 'relations' => []];
			}
		}

		if ($answer['available'] === false) {
			if (in_array($answer['reason'], self::RELATION_READ_ABSENT, true) === true) {
				$this->logger->notice(
					'Belangenconflict: the family relation step is not available',
					['caseId' => $caseId, 'reason' => $answer['reason']]
				);
				return [
					'conflict' => false,
					'relationCheck' => self::RELATION_CHECK_NOT_AVAILABLE,
					'relationCheckReason' => $answer['reason'],
				];
			}

			$this->logger->warning(
				'Belangenconflict: the family relation read gave no answer, blocking',
				['caseId' => $caseId, 'reason' => $answer['reason']]
			);
			return ['conflict' => true, 'reason' => self::REASON_RELATION_INDETERMINATE];
		}

		$applicantHash = self::hashBsn(bsn: $applicantBsn);
		foreach ($answer['relations'] as $relation) {
			if (hash_equals($applicantHash, self::hashBsn(bsn: $relation['burgerservicenummer'])) === true) {
				return ['conflict' => true, 'reason' => $relation['relation']];
			}
		}

		return ['conflict' => false, 'relationCheck' => self::RELATION_CHECK_DONE];
	}//end relationFromRegistry()

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

	/*
	 * NO clearConflict() HERE.
	 *
	 * It removed an entry from the in-memory `$registered` map above and had
	 * no caller. Neither has `registerConflict()`, which fills that map — the
	 * manual-override pair is unreached from either end, and the map is
	 * request-scoped, so an entry cannot survive long enough to need clearing.
	 * `checkConflict()`, the live method, is unaffected.
	 */
}//end class
