<?php

/**
 * Procest SearchesObjects trait
 *
 * Shared helper that bridges procest's lib/ to OpenRegister's real
 * ObjectService search API. OpenRegister's ObjectService has NO
 * `findObjects()` method — the correct entry points are
 * `searchObjects(array $query)` (numeric register/schema IDs in the
 * `@self` block, object-field filters at the top level) and
 * `searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters)`
 * (slug-aware bridge that resolves the slugs and merges `@self` itself).
 *
 * Every procest service/controller/job that previously called the
 * non-existent `findObjects()` (which 500-ed the entire complaints,
 * hearing, DSO and raadsinformatie surface) now funnels through
 * {@see self::searchObjectsAsArrays()}, which:
 *   - picks the numeric-ID path or the slug path automatically,
 *   - keeps the register/schema context in `@self`,
 *   - passes object-field filters (status, caseType, …) and OpenRegister
 *     pagination keys (`_limit`, `_offset`) straight through, and
 *   - normalises the `ObjectEntity[]|int` return into a plain
 *     `array<int, array<string, mixed>>` so existing array-access callers
 *     (`$row['field']`) keep working.
 *
 * @category Service
 * @package  OCA\Procest\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */

namespace OCA\Procest\Service\Support;

/**
 * Trait providing the canonical OpenRegister object-search bridge.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */
trait SearchesObjects
{
    /**
     * Search OpenRegister objects and return them as plain associative arrays.
     *
     * Replacement for the non-existent `ObjectService::findObjects()`. Chooses
     * the numeric-ID search path when both register and schema are numeric
     * identifiers, otherwise delegates to the slug-aware bridge.
     *
     * @param object               $objectService The OpenRegister ObjectService instance.
     * @param int|string           $register      Register numeric ID or slug.
     * @param int|string           $schema        Schema numeric ID or slug.
     * @param array<string, mixed> $filters       Object-field filters plus OpenRegister
     *                                            pagination keys (`_limit`, `_offset`).
     *
     * @return array<int, array<string, mixed>> Matching objects as associative arrays.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When a slug cannot be resolved
     *                                                    in the caller's organisation.
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
     */
    protected function searchObjectsAsArrays(
        object $objectService,
        int | string $register,
        int | string $schema,
        array $filters=[]
    ): array {
        $registerIsNumeric = (is_int($register) === true || ctype_digit((string) $register) === true);
        $schemaIsNumeric   = (is_int($schema) === true || ctype_digit((string) $schema) === true);

        // Slug path: when either identifier is a slug, delegate to the
        // slug-aware bridge which resolves the slugs and merges `@self` itself.
        if ($registerIsNumeric === false || $schemaIsNumeric === false) {
            return $this->normaliseObjectRows(
                rows: $objectService->searchObjectsBySlug(
                    (string) $register,
                    (string) $schema,
                    $filters
                )
            );
        }

        // Numeric path: register/schema go into the `@self` metadata block,
        // object-field filters stay at the top level.
        $query = $filters;
        $self  = ($query['@self'] ?? []);
        $self['register'] = (int) $register;
        $self['schema']   = (int) $schema;
        $query['@self']   = $self;

        return $this->normaliseObjectRows(rows: $objectService->searchObjects($query));
    }//end searchObjectsAsArrays()

    /**
     * Fetch a single OpenRegister object by id and return it as a plain array.
     *
     * Replacement for the non-existent `ObjectService::findObject()`. The real
     * single-object entry point is `find(int|string $id, ?array $_extend, bool
     * $files, register, schema)`, which returns an `ObjectEntity` or throws
     * `DoesNotExistException` when the id is unknown. Procest callers expect a
     * nullable associative array, so a missing object is mapped to `null`.
     *
     * @param object     $objectService The OpenRegister ObjectService instance.
     * @param int|string $register      Register numeric ID or slug.
     * @param int|string $schema        Schema numeric ID or slug.
     * @param string     $id            Object UUID / identifier.
     *
     * @return array<string, mixed>|null The object as an associative array, or
     *                                   null when it does not exist.
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
     */
    protected function findObjectAsArray(
        object $objectService,
        int | string $register,
        int | string $schema,
        string $id
    ): ?array {
        try {
            $object = $objectService->find(
                id: $id,
                register: $register,
                schema: $schema
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end findObjectAsArray()

    /**
     * Coerce a searchObjects()/searchObjectsBySlug() return into a list of arrays.
     *
     * The OpenRegister search API returns `ObjectEntity[]` for a normal query or
     * an `int` in count mode; either way callers in procest expect a list of
     * associative arrays. ObjectEntity instances are flattened via jsonSerialize().
     *
     * @param mixed $rows Raw search result.
     *
     * @return array<int, array<string, mixed>> Normalised list of object arrays.
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
     */
    private function normaliseObjectRows(mixed $rows): array
    {
        if (is_array($rows) === false) {
            return [];
        }

        $list = [];
        foreach ($rows as $item) {
            if (is_array($item) === true) {
                $list[] = $item;
                continue;
            }

            if (is_object($item) === false) {
                continue;
            }

            // OpenRegister returns ObjectEntity instances which expose
            // jsonSerialize(); fall back to a plain object cast for any other
            // object shape so callers always receive associative arrays.
            if (method_exists($item, 'jsonSerialize') === true) {
                $serialized = $item->jsonSerialize();
                if (is_array($serialized) === true) {
                    $list[] = $serialized;
                    continue;
                }
            }

            $list[] = (array) $item;
        }//end foreach

        return $list;
    }//end normaliseObjectRows()
}//end trait
