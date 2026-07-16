<?php

/**
 * Procest Medewerker Identity Resolver Interface.
 *
 * Resolves the SERVER-SIDE identity of a case worker (medewerker) for the
 * belangenconflict check.
 *
 * Why a seam rather than a lookup: Nextcloud holds no BSN for civil servants.
 * `BurgerIdentificationService::resolveFromDigiD()` is the *citizen* path (it
 * consumes a BSN from a validated DigiD assertion) and cannot serve this. So no
 * binding ships by default and the resolver is dormant.
 *
 * That dormancy is deliberately NOT a fail-open: an unbound resolver makes the
 * conflict check *indeterminate*, and `ConflictOfInterestService` treats
 * indeterminate as a CONFLICT (blocks) rather than "no conflict". A deployment
 * that can resolve medewerker identity (e.g. from an HR system or an IdP
 * attribute) binds an implementation and gets real detection; a deployment that
 * cannot gets a fail-closed block, never a silent pass.
 *
 * AVG / GDPR art. 9 — BSN is special-category personal data:
 *   - This value is used IN MEMORY only, for the duration of one check.
 *   - It MUST NEVER be logged, persisted, or returned in an API payload.
 *     `ConflictOfInterestService` compares SHA-256 hashes (`hash_equals`) rather
 *     than raw values, and logs only a truncated hash.
 *   - A raw BSN (not a hash) is returned here because the dormant BRP / Haal
 *     Centraal relationship lookup (`relaties` envelope) can only be queried by
 *     BSN. Returning a hash instead would make that lookup permanently
 *     unreachable — stranding a capability, which is the same orphaned-capability
 *     defect class this change exists to close.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Resolves a case worker's identity for belangenconflict detection.
 */
interface MedewerkerIdentityResolverInterface
{
    /**
     * Resolve the BSN of the case worker behind a Nextcloud user id.
     *
     * Implementations MUST return null when the identity cannot be established,
     * so the caller can fail closed. Implementations MUST NOT log the returned
     * value.
     *
     * @param string $userId The Nextcloud user id of the case worker.
     *
     * @return string|null The worker's BSN, or null when it cannot be resolved.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function bsnFor(string $userId): ?string;
}//end interface
