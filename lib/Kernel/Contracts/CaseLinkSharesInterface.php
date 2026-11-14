<?php

/**
 * Contract of CaseLinkShares
 *
 * The public surface of CaseLinkShares that other components call, so they depend
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

/**
 * Contract of CaseLinkShares.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseLinkSharesInterface {

	/**
	 * The failure is this instance's own configuration, not OpenRegister.
	 *
	 * Carried on the error array so {@see \OCA\Dossiq\Controller\CaseSharingController}
	 * can answer 503 and name the gap, instead of 502 and a bare
	 * "Service unavailable" that points at an app which did nothing wrong.
	 *
	 * @var string
	 */
	public const REASON_NOT_CONFIGURED = 'not-configured';

	/**
	 * Whether an access link is one this case minted.
	 *
	 * The IDOR guard in front of every revoke, pause and preview: a handler of
	 * case A must not reach case B's link by naming its id.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $caseId The candidate case UUID.
	 *
	 * @return bool True when the case carries a share holding that link.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function belongsToCase(int $linkId, string $caseId): bool;

	/**
	 * What the holder of a case link reads.
	 *
	 * The anchor is read off the stored address rather than kept in a column of
	 * its own. The anchor IS the credential, and one copy of it in the register
	 * is one too many already.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $caseId The case the link is on.
	 *
	 * @return array<string, mixed>|null The body a holder is served, or null.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function holderPreview(int $linkId, string $caseId): ?array;

	/**
	 * Every access-link share on a case, each carrying the state of its link.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The shares.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function listForCase(string $caseId): array;

	/**
	 * Switch a share's link off, or back on.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $userId The principal asking.
	 * @param bool $paused True to switch it off.
	 *
	 * @return array<string, mixed>|null The updated link, or null.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function pauseLink(int $linkId, string $userId, bool $paused): ?array;

	/**
	 * Revoke the access link a share was minted as.
	 *
	 * The caller MUST have already authorised this against the owning case.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $userId The principal asking.
	 *
	 * @return bool True when OpenRegister revoked it.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function revokeLink(int $linkId, string $userId): bool;
}//end interface
