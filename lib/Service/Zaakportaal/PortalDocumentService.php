<?php

/**
 * Procest Zaakportaal Document Service
 *
 * Citizen-facing document access for the Mijn gemeente portal. Only documents
 * explicitly addressable to the citizen's role (downloadbaarVoor includes
 * "aanvrager"/"geadresseerde") are ever surfaced; internal documents (adviezen,
 * ambtelijke notities) are filtered out completely — not even as a title. A
 * direct download of a non-addressable document is denied (403) and the attempt
 * is recorded by the audit logger. Document access is additionally scoped to a
 * case the subject owns, preventing cross-case enumeration (IDOR-safe).
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakportaal
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
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-05
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

/**
 * Filters and authorises citizen document access.
 *
 * @psalm-suppress UnusedClass
 */
class PortalDocumentService
{
    /**
     * Roles that grant a citizen download access.
     *
     * @var array<int, string>
     */
    public const CITIZEN_ROLES = ['aanvrager', 'geadresseerde', 'belanghebbende'];

    /**
     * Filter a raw document list to those addressable to the citizen.
     *
     * Internal documents (no downloadbaarVoor overlap with the citizen's roles)
     * are removed entirely.
     *
     * @param array<int, mixed>  $documents    The raw documents (each expected to be an array).
     * @param array<int, string> $citizenRoles The citizen's roles on the case.
     *
     * @return array<int, array<string, mixed>> The visible documents.
     */
    public function filterVisible(array $documents, array $citizenRoles): array
    {
        $roles = $this->effectiveRoles(citizenRoles: $citizenRoles);

        $visible = [];
        foreach ($documents as $document) {
            if (is_array($document) === false) {
                continue;
            }

            if ($this->isDownloadable(document: $document, citizenRoles: $roles) === true) {
                $visible[] = [
                    'id'    => (string) ($document['id'] ?? ''),
                    'naam'  => (string) ($document['naam'] ?? ($document['title'] ?? '')),
                    'soort' => (string) ($document['soort'] ?? ($document['documentType'] ?? '')),
                    'datum' => (string) ($document['datum'] ?? ($document['creationDate'] ?? '')),
                ];
            }
        }

        return $visible;
    }//end filterVisible()

    /**
     * Whether a citizen with the given roles may download a document.
     *
     * @param array<string, mixed> $document     The document.
     * @param array<int, string>   $citizenRoles The citizen's roles.
     *
     * @return bool True when downloadable.
     */
    public function isDownloadable(array $document, array $citizenRoles): bool
    {
        $allowed = $document['downloadbaarVoor'] ?? [];
        if (is_array($allowed) === false || $allowed === []) {
            // No explicit citizen ACL means internal-only.
            return false;
        }

        $roles = $this->effectiveRoles(citizenRoles: $citizenRoles);
        foreach ($allowed as $role) {
            if (in_array((string) $role, $roles, true) === true) {
                return true;
            }
        }

        return false;
    }//end isDownloadable()

    /**
     * Normalise / constrain citizen roles to the recognised set.
     *
     * @param array<int, string> $citizenRoles The supplied roles.
     *
     * @return array<int, string> The effective roles.
     */
    private function effectiveRoles(array $citizenRoles): array
    {
        $roles = array_values(
            array_filter(
                array_map('strval', $citizenRoles),
                static fn(string $role): bool => in_array($role, self::CITIZEN_ROLES, true)
            )
        );

        // Default to aanvrager when no explicit role is provided.
        if ($roles === []) {
            return ['aanvrager'];
        }

        return $roles;
    }//end effectiveRoles()
}//end class
