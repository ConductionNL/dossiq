<?php

/**
 * Contract of CaseAccessGuard
 *
 * The public surface of CaseAccessGuard that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;

/**
 * Contract of CaseAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAccessGuardInterface {

	/**
	 * Assert that the given user may mutate the given case.
	 *
	 * Decision table (fails closed at every branch):
	 *   - admin                  -> allow
	 *   - OpenRegister absent    -> DENY (never "skip the check")
	 *   - case not resolvable    -> DENY (collapsed with denied: no existence oracle)
	 *   - uid === case.assignee  -> allow
	 *   - otherwise              -> DENY
	 *
	 * Group existence plays no part. The absence of any group can never grant
	 * access.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser $user The authenticated user.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user may not mutate this case.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function assertCaseMutationAccess(string $caseId, IUser $user): void;

	/**
	 * Whether the given user may mutate the given case.
	 *
	 * 🔴 THE VERB DOES NOT WIDEN, AND NOW IT DOES NOT WIDEN ACROSS AN APP
	 * BOUNDARY EITHER (row Q13.23, D-3). A read on a parent reaches its
	 * deelzaken; a right to see a case is not a right to change its children.
	 * What pins it is the ABSENCE of a call to
	 * {@see self::holdsPlatformGrant()} here: that is where inheritance
	 * arrives now, so a mutation path that consulted it would inherit the
	 * write the whole rule exists to refuse. Anything added to this method
	 * that asks the platform, or that resolves an ancestor, is the
	 * regression.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the user handles the case itself or is an admin.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaseMutationAccess(string $caseId, IUser $user): bool;

	/**
	 * Whether the given user may read the given case.
	 *
	 * Reads are granted to a slightly wider set than mutations, because a case
	 * is worked on by more people than the one named in `assignee`: the
	 * `assignees` array is honoured as well. It is still a real per-case
	 * relationship, and it still fails closed at every branch — an
	 * unresolvable case, an absent OpenRegister, or an unconfigured schema all
	 * DENY.
	 *
	 * Deliberately NOT delegated to
	 * {@see Sharing\CaseAccessPolicy::canUserAccessCase()}: that one returns
	 * TRUE when OpenRegister is absent, when the schema is unconfigured, and
	 * when the lookup throws. Those three fail-OPEN branches are acceptable for
	 * the sharing UI it was written for and are not acceptable here.
	 *
	 * A READ REACHES A DEELZAAK FROM ITS PARENT, AND DOSSIQ NO LONGER WALKS
	 * FOR IT. openregister#3873 resolves a per-object grant over the declared
	 * `x-openregister-hierarchy` edge, so a grant on a parent case answers for
	 * its deelzaken, in the layer that owns grants. This guard asked the same
	 * question a second time, over the same edge, in its own loop; two
	 * resolvers of one question is what ADR-022 refuses and what D-2 of
	 * `deelzaken-inherit-the-parent-grants` said would go the moment the
	 * platform could answer. It can, so it has.
	 *
	 * WHAT IS LEFT IS THREE WAYS IN, AND NONE IS A SCHEMA READ RULE THAT LETS EVERY USER IN.
	 *
	 *  - the per-case RELATIONSHIP: the caller is named on the case, as its
	 *    assignee or in `assignees`. dossiq's own rule, unchanged since the
	 *    gate-7 remediation, and the reason this guard exists at all;
	 *  - a per-object GRANT, which OpenRegister resolves and which now
	 *    includes an inherited one.
	 *  - membership of a HANDLING TEAM of the case's case type, the team
	 *    OpenRegister's own case rule lets read the case (REQ-ACC-10), so a
	 *    team member opens an unclaimed case of their team.
	 *
	 * 🔴 IT DOES NOT DEFER WHOLESALE TO `loadCase()` RESOLVING. That was the
	 * tempting shape and it is a silent widening: `ObjectService::find()`
	 * applies the SCHEMA's read rule, and a case schema whose rule is
	 * `authenticated` resolves every case for every logged-in user. Deferring
	 * would have turned this guard into a check that every authenticated user
	 * passes, on an instance where nothing looked different afterwards. A
	 * grant is a deliberate invitation; a schema rule is not.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the user works on the case, holds a grant on it, is in its handling team, or is an admin.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaseReadAccess(string $caseId, IUser $user): bool;
}//end interface
