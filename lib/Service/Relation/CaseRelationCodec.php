<?php

/**
 * Procest case-relation codec.
 *
 * Owns the on-disk shape of the `case.relatedCases` field and every pure
 * operation over a relation list: decoding it (the field is a JSON-encoded
 * string, but an already-decoded array is tolerated because the ZGW inbound
 * mapping layer writes it directly), building a single entry, and the
 * pair-level membership and removal used to keep both sides of a symmetric
 * relation consistent.
 *
 * Split out of CaseRelationService so that service keeps only the policy —
 * which guards fail closed, and that every add/remove touches both cases —
 * while the encoding contract lives in one place. Everything here is pure: no
 * OpenRegister access, no session, no logging.
 *
 * An entry that names no case is dropped rather than raising: a partially
 * written relation must not make the whole list unreadable.
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
 * Encodes, decodes and edits the typed peer-relation list of a case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationCodec
{
    /**
     * Build a single relation entry, carrying the optional clarification.
     *
     * @param string      $caseId      Referenced case UUID.
     * @param string      $aardRelatie Relation type.
     * @param string|null $toelichting Optional free-text clarification.
     *
     * @return array<string, string>
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function buildEntry(string $caseId, string $aardRelatie, ?string $toelichting): array
    {
        $entry = ['caseId' => $caseId, 'aardRelatie' => $aardRelatie];
        if ($toelichting !== null && $toelichting !== '') {
            $entry['toelichting'] = $toelichting;
        }

        return $entry;
    }//end buildEntry()

    /**
     * Decode the JSON-encoded `relatedCases` field into a list of relation
     * entries, tolerating an already-array shape.
     *
     * @param array<string, mixed> $case Case object.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function decode(array $case): array
    {
        $entries = [];
        foreach ($this->rawRelationList(case: $case) as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $entry = $this->decodeRelationEntry(item: $item);
            if ($entry === null) {
                continue;
            }

            $entries[] = $entry;
        }//end foreach

        return $entries;
    }//end decode()

    /**
     * Whether a `{caseId, aardRelatie}` pair already exists in a relation list.
     *
     * @param array<int, array<string, mixed>> $relations   Relation entries.
     * @param string                           $caseId      Target case UUID.
     * @param string                           $aardRelatie Relation type.
     *
     * @return bool
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function hasPair(array $relations, string $caseId, string $aardRelatie): bool
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
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function removePair(array $relations, string $caseId, string $aardRelatie): array
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
     * Return a copy of the relation list with every entry naming a case removed.
     *
     * @param array<int, array<string, mixed>> $relations Relation entries.
     * @param string                           $caseId    Case UUID to strip.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function removeAllForCase(array $relations, string $caseId): array
    {
        return array_values(
            array_filter(
                $relations,
                static fn (array $relation): bool => (string) ($relation['caseId'] ?? '') !== $caseId
            )
        );
    }//end removeAllForCase()

    /**
     * Read the raw `relatedCases` payload as a list, accepting either the
     * JSON-encoded string shape or an already-decoded array.
     *
     * @param array<string, mixed> $case Case object.
     *
     * @return array<mixed> The raw relation list, or [] when unusable.
     */
    private function rawRelationList(array $case): array
    {
        $raw  = ($case['relatedCases'] ?? null);
        $list = [];
        if (is_array($raw) === true) {
            $list = $raw;
        }

        if (is_string($raw) === true && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                $list = $decoded;
            }
        }

        return $list;
    }//end rawRelationList()

    /**
     * Normalise one raw relation item into a relation entry.
     *
     * @param array<string, mixed> $item Raw relation item.
     *
     * @return array<string, string>|null The entry, or null when it names no case.
     */
    private function decodeRelationEntry(array $item): ?array
    {
        $targetId = (string) ($item['caseId'] ?? '');
        if ($targetId === '') {
            return null;
        }

        $entry = [
            'caseId'      => $targetId,
            'aardRelatie' => (string) ($item['aardRelatie'] ?? ''),
        ];
        if (isset($item['toelichting']) === true && (string) $item['toelichting'] !== '') {
            $entry['toelichting'] = (string) $item['toelichting'];
        }

        return $entry;
    }//end decodeRelationEntry()
}//end class
