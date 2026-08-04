<?php

/**
 * Procest case-hierarchy overlap guard.
 *
 * Answers one question for the peer-relation surface: are these two cases
 * already linked through the hoofdzaak/deelzaak hierarchy? When they are, a
 * typed peer relation would express the same link twice, so CaseRelationService
 * refuses to create it.
 *
 * The hierarchy itself (the `parentCase` field) is DeelzaakService's concern.
 * This class only *reads* it, and only to decide overlap — it never writes a
 * parent link. It is split out of CaseRelationService precisely so that
 * read-only borrowing of another aggregate's field is visible in one place.
 *
 * The `parentCase` reference is accepted in both shapes OpenRegister can
 * return it in: a scalar UUID, or an expanded object carrying `id`/`uuid`.
 *
 * @category Service
 * @package  OCA\Procest\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Relation;

/**
 * Detects an existing hoofdzaak/deelzaak link between two cases.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseHierarchyOverlapGuard
{
    /**
     * Determine whether two cases are already linked through the deelzaak
     * (parent/child) hierarchy in either direction.
     *
     * @param array<string, mixed> $caseA First case object.
     * @param array<string, mixed> $caseB Second case object.
     *
     * @return bool
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function areLinked(array $caseA, array $caseB): bool
    {
        $idA = (string) ($caseA['id'] ?? ($caseA['@self']['id'] ?? ''));
        $idB = (string) ($caseB['id'] ?? ($caseB['@self']['id'] ?? ''));

        $parentA = $this->parentRef(case: $caseA);
        $parentB = $this->parentRef(case: $caseB);

        if ($idB !== '' && $parentA === $idB) {
            return true;
        }

        if ($idA !== '' && $parentB === $idA) {
            return true;
        }

        return false;
    }//end areLinked()

    /**
     * Read the `parentCase` reference UUID out of a case array (scalar or
     * expanded-object shape).
     *
     * @param array<string, mixed> $case Case object.
     *
     * @return string Parent UUID or '' when absent.
     */
    private function parentRef(array $case): string
    {
        $parent = ($case['parentCase'] ?? null);
        if (is_string($parent) === true) {
            return $parent;
        }

        if (is_array($parent) === true) {
            $ref = ($parent['id'] ?? ($parent['uuid'] ?? ''));
            if (is_string($ref) === true) {
                return $ref;
            }

            return '';
        }

        return '';
    }//end parentRef()
}//end class
