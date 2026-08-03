<?php

/**
 * Procest Case Relation (peer / relevanteAndereZaken) Service
 *
 * Typed peer relations between cases on the existing `case.relatedCases`
 * field, per RGBZ/ZRC `relevanteAndereZaken`. Relations are typed with an
 * `aardRelatie` (`vervolg` | `onderwerp` | `bijdrage`), stored symmetrically
 * (visible from both cases), guarded against self-relations, duplicates and
 * hierarchy overlap, and require OpenRegister read access to both cases.
 *
 * This service is the ONLY writer of `relatedCases` — it keeps both sides
 * consistent on add, remove and delete-cleanup, and normalises direct field
 * writes (e.g. the ZGW inbound path) back to symmetry.
 *
 * Hierarchy (hoofdzaak/deelzaak, the `parentCase` field) stays the concern of
 * {@see DeelzaakService}; this service refuses to mirror it as a peer relation.
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Service for typed peer relations between cases.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationService
{

    use SearchesObjects;

    /**
     * Allowed ZRC relation types (`aardRelatie`).
     *
     * @var array<int, string>
     */
    public const RELATION_TYPES = ['vervolg', 'onderwerp', 'bijdrage'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Shared OR/settings resolver.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List the typed peer relations stored on a case.
     *
     * Returns the decoded `relatedCases` array; each entry is
     * `{caseId, aardRelatie, toelichting?}`. Returns `[]` when the case is
     * missing/unreadable or carries no relations.
     *
     * @param string $caseId Case UUID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function listRelations(string $caseId): array
    {
        $case = $this->fetchCase(caseUuid: $caseId);
        if ($case === null) {
            return [];
        }

        return $this->decodeRelations(case: $case);
    }//end listRelations()

    /**
     * Add a typed peer relation symmetrically to both cases.
     *
     * Guards (all fail closed):
     *   - `aardRelatie` must be one of {@see self::RELATION_TYPES};
     *   - no self-relation (`caseId == targetId`);
     *   - no duplicate `{caseId, aardRelatie}` pair;
     *   - no overlap with an existing direct hoofdzaak/deelzaak hierarchy link;
     *   - the actor must have OR read access to BOTH cases (enforced because
     *     {@see self::fetchCase()} resolves through the session's ObjectService,
     *     which applies OpenRegister RBAC — an unreadable case resolves to null).
     *
     * @param string      $caseId      Origin case UUID.
     * @param string      $targetId    Target case UUID.
     * @param string      $aardRelatie Relation type.
     * @param string|null $toelichting Optional free-text clarification (procest-local).
     *
     * @return array{ok: bool, reason?: string, detail?: string}
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function addRelation(
        string $caseId,
        string $targetId,
        string $aardRelatie,
        ?string $toelichting=null
    ): array {
        if (in_array($aardRelatie, self::RELATION_TYPES, true) === false) {
            return ['ok' => false, 'reason' => 'invalid_aard_relatie'];
        }

        if ($caseId === '' || $targetId === '') {
            return ['ok' => false, 'reason' => 'missing_case_id'];
        }

        if ($caseId === $targetId) {
            return ['ok' => false, 'reason' => 'self_relation'];
        }

        // OR-RBAC read access to BOTH cases (fail closed on either miss).
        $origin = $this->fetchCase(caseUuid: $caseId);
        $target = $this->fetchCase(caseUuid: $targetId);
        if ($origin === null || $target === null) {
            return ['ok' => false, 'reason' => 'access_denied'];
        }

        // Hierarchy-overlap guard — the parent/sub-case link already expresses
        // the relation, so refuse to also peer-link the same pair.
        if ($this->isHierarchyLinked(caseA: $origin, caseB: $target) === true) {
            return [
                'ok'     => false,
                'reason' => 'hierarchy_overlap',
                'detail' => 'These cases are already linked through the hoofdzaak/deelzaak hierarchy.',
            ];
        }

        $originRelations = $this->decodeRelations(case: $origin);
        if ($this->hasPair(relations: $originRelations, caseId: $targetId, aardRelatie: $aardRelatie) === true) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }

        $entry = ['caseId' => $targetId, 'aardRelatie' => $aardRelatie];
        if ($toelichting !== null && $toelichting !== '') {
            $entry['toelichting'] = $toelichting;
        }

        $originRelations[] = $entry;
        $this->persistRelations(case: $origin, relations: $originRelations);

        // Symmetric counterpart — same type names the link, the UI renders
        // direction-aware labels.
        $targetRelations = $this->decodeRelations(case: $target);
        if ($this->hasPair(relations: $targetRelations, caseId: $caseId, aardRelatie: $aardRelatie) === false) {
            $inverse = ['caseId' => $caseId, 'aardRelatie' => $aardRelatie];
            if ($toelichting !== null && $toelichting !== '') {
                $inverse['toelichting'] = $toelichting;
            }

            $targetRelations[] = $inverse;
            $this->persistRelations(case: $target, relations: $targetRelations);
        }

        return ['ok' => true];
    }//end addRelation()

    /**
     * Remove a typed peer relation from BOTH cases.
     *
     * @param string $caseId      Origin case UUID.
     * @param string $targetId    Target case UUID.
     * @param string $aardRelatie Relation type to remove.
     *
     * @return array{ok: bool, reason?: string}
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function removeRelation(string $caseId, string $targetId, string $aardRelatie): array
    {
        if ($caseId === '' || $targetId === '') {
            return ['ok' => false, 'reason' => 'missing_case_id'];
        }

        $origin = $this->fetchCase(caseUuid: $caseId);
        $target = $this->fetchCase(caseUuid: $targetId);
        if ($origin === null || $target === null) {
            return ['ok' => false, 'reason' => 'access_denied'];
        }

        $originRelations = $this->removePair(
            relations: $this->decodeRelations(case: $origin),
            caseId: $targetId,
            aardRelatie: $aardRelatie
        );
        $this->persistRelations(case: $origin, relations: $originRelations);

        $targetRelations = $this->removePair(
            relations: $this->decodeRelations(case: $target),
            caseId: $caseId,
            aardRelatie: $aardRelatie
        );
        $this->persistRelations(case: $target, relations: $targetRelations);

        return ['ok' => true];
    }//end removeRelation()

    /**
     * Remove every counterpart entry pointing at a case that is being deleted.
     *
     * Invoked from the case-deletion path (next to the deelzaak orphan cleanup)
     * so no dangling references survive. Scans every case whose `relatedCases`
     * references the deleted UUID and strips those entries.
     *
     * @param string $caseId UUID of the case being deleted.
     *
     * @return int Number of counterpart cases updated.
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function cleanupForDeletedCase(string $caseId): int
    {
        if ($caseId === '') {
            return 0;
        }

        $deleted = $this->fetchCase(caseUuid: $caseId);
        // Even when the case is already gone we still scan counterparts: the
        // relation entries on OTHER cases are what must be cleaned up.
        $counterpartIds = [];
        if ($deleted !== null) {
            foreach ($this->decodeRelations(case: $deleted) as $relation) {
                $ref = (string) ($relation['caseId'] ?? '');
                if ($ref !== '' && in_array($ref, $counterpartIds, true) === false) {
                    $counterpartIds[] = $ref;
                }
            }
        }

        $updated = 0;
        foreach ($counterpartIds as $counterpartId) {
            $counterpart = $this->fetchCase(caseUuid: $counterpartId);
            if ($counterpart === null) {
                continue;
            }

            $relations = $this->decodeRelations(case: $counterpart);
            $stripped  = array_values(
                array_filter(
                    $relations,
                    static fn (array $relation): bool => (string) ($relation['caseId'] ?? '') !== $caseId
                )
            );

            if (count($stripped) !== count($relations)) {
                $this->persistRelations(case: $counterpart, relations: $stripped);
                $updated++;
            }
        }//end foreach

        return $updated;
    }//end cleanupForDeletedCase()

    /**
     * Restore symmetry after a direct write to `relatedCases` (e.g. ZGW inbound).
     *
     * For each relation on the given case, ensures the counterpart case carries
     * the matching inverse entry. Used by the ZGW inbound path so guards and
     * symmetry hold even when the field was written directly by the mapping
     * layer rather than through {@see self::addRelation()}.
     *
     * @param string $caseId Case UUID whose relations were written directly.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function normalise(string $caseId): void
    {
        if ($caseId === '') {
            return;
        }

        $case = $this->fetchCase(caseUuid: $caseId);
        if ($case === null) {
            return;
        }

        foreach ($this->decodeRelations(case: $case) as $relation) {
            $targetId    = (string) ($relation['caseId'] ?? '');
            $aardRelatie = (string) ($relation['aardRelatie'] ?? '');
            if ($targetId === '' || $targetId === $caseId
                || in_array($aardRelatie, self::RELATION_TYPES, true) === false
            ) {
                continue;
            }

            $target = $this->fetchCase(caseUuid: $targetId);
            if ($target === null) {
                continue;
            }

            $targetRelations = $this->decodeRelations(case: $target);
            if ($this->hasPair(relations: $targetRelations, caseId: $caseId, aardRelatie: $aardRelatie) === false) {
                $targetRelations[] = ['caseId' => $caseId, 'aardRelatie' => $aardRelatie];
                $this->persistRelations(case: $target, relations: $targetRelations);
            }
        }//end foreach
    }//end normalise()

    /**
     * Determine whether two cases are already linked through the deelzaak
     * (parent/child) hierarchy in either direction.
     *
     * @param array<string, mixed> $caseA First case object.
     * @param array<string, mixed> $caseB Second case object.
     *
     * @return bool
     */
    private function isHierarchyLinked(array $caseA, array $caseB): bool
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
    }//end isHierarchyLinked()

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

    /**
     * Decode the JSON-encoded `relatedCases` field into a list of relation
     * entries, tolerating an already-array shape.
     *
     * @param array<string, mixed> $case Case object.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeRelations(array $case): array
    {
        $raw = ($case['relatedCases'] ?? null);
        if (is_array($raw) === true) {
            $list = $raw;
        } else if (is_string($raw) === true && $raw !== '') {
            $decoded = json_decode($raw, true);
            $list    = [];
            if (is_array($decoded) === true) {
                $list = $decoded;
            }
        } else {
            return [];
        }

        $entries = [];
        foreach ($list as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $targetId = (string) ($item['caseId'] ?? '');
            if ($targetId === '') {
                continue;
            }

            $entry = [
                'caseId'      => $targetId,
                'aardRelatie' => (string) ($item['aardRelatie'] ?? ''),
            ];
            if (isset($item['toelichting']) === true && (string) $item['toelichting'] !== '') {
                $entry['toelichting'] = (string) $item['toelichting'];
            }

            $entries[] = $entry;
        }//end foreach

        return $entries;
    }//end decodeRelations()

    /**
     * Whether a `{caseId, aardRelatie}` pair already exists in a relation list.
     *
     * @param array<int, array<string, mixed>> $relations   Relation entries.
     * @param string                           $caseId      Target case UUID.
     * @param string                           $aardRelatie Relation type.
     *
     * @return bool
     */
    private function hasPair(array $relations, string $caseId, string $aardRelatie): bool
    {
        foreach ($relations as $relation) {
            if ((string) ($relation['caseId'] ?? '') === $caseId
                && (string) ($relation['aardRelatie'] ?? '') === $aardRelatie
            ) {
                return true;
            }
        }

        return false;
    }//end hasPair()

    /**
     * Return a copy of the relation list with the given pair removed.
     *
     * @param array<int, array<string, mixed>> $relations   Relation entries.
     * @param string                           $caseId      Target case UUID.
     * @param string                           $aardRelatie Relation type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function removePair(array $relations, string $caseId, string $aardRelatie): array
    {
        return array_values(
            array_filter(
                $relations,
                static fn (array $relation): bool => (
                    (string) ($relation['caseId'] ?? '') !== $caseId
                    || (string) ($relation['aardRelatie'] ?? '') !== $aardRelatie
                )
            )
        );
    }//end removePair()

    /**
     * Fetch a single case object by UUID through the session's ObjectService.
     *
     * Resolving via OpenRegister applies its per-object RBAC for the current
     * user, so an unreadable case resolves to null — this is the access guard.
     *
     * @param string $caseUuid Case UUID.
     *
     * @return array<string, mixed>|null
     */
    private function fetchCase(string $caseUuid): ?array
    {
        if ($caseUuid === '') {
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $obj = $objectService->find($caseUuid, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'CaseRelationService: case lookup failed',
                ['uuid' => $caseUuid, 'error' => $e->getMessage()]
            );
            return null;
        }

        if ($obj === null) {
            return null;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $obj = $obj->jsonSerialize();
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return null;
    }//end fetchCase()

    /**
     * Persist a relation list back onto a case, JSON-encoding the field
     * (the `relatedCases` field is a JSON-encoded string).
     *
     * @param array<string, mixed>             $case      Case object to update.
     * @param array<int, array<string, mixed>> $relations Relation entries.
     *
     * @return void
     */
    private function persistRelations(array $case, array $relations): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if ($register === '' || $schema === '') {
            return;
        }

        $payload = $case;
        $payload['relatedCases'] = json_encode(array_values($relations));

        try {
            $objectService->saveObject(
                object: $payload,
                register: $register,
                schema: $schema,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseRelationService: failed to persist relatedCases',
                ['error' => $e->getMessage()]
            );
        }
    }//end persistRelations()
}//end class
