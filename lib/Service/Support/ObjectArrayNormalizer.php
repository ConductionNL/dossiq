<?php

/**
 * Procest OpenRegister return-value normalizer.
 *
 * OpenRegister's ObjectService returns either a plain array (older API) or an
 * entity exposing `jsonSerialize()` / `toArray()` (newer API), so every caller
 * that wants an associative array has to collapse both shapes. Split out of
 * the parafering services, which each carried their own private copy.
 *
 * Two methods, deliberately: the strict form treats an object it cannot
 * serialise as "no data" and answers `[]`, while the cast form falls back to
 * `(array) $value` and exposes the object's public properties. The route
 * engine relied on the cast fallback and the action recorder relied on the
 * empty answer — collapsing them into one behaviour would silently change one
 * of the two, so both are kept and named for what they do.
 *
 * @category Service
 * @package  OCA\Procest\Service\Support
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
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Support;

/**
 * Collapses OpenRegister's array-or-entity return shape into an array.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */
class ObjectArrayNormalizer
{
    /**
     * Normalise a return value to an array, answering `[]` for anything that
     * cannot be serialised.
     *
     * @param mixed $value The value to normalise.
     *
     * @return array<string, mixed> The associative array, or `[]`.
     *
     * @spec openspec/changes/parafering-actions/tasks.md#T02
     */
    public function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return ($this->serialiseObject(value: $value) ?? []);
    }//end toArray()

    /**
     * Normalise a return value to an array, falling back to an object cast.
     *
     * Use where the caller previously relied on an un-serialisable object
     * still yielding its public properties rather than an empty array.
     *
     * @param mixed $value The value to normalise.
     *
     * @return array<string, mixed> The associative array, or `[]` for scalars/null.
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
     */
    public function toArrayWithCast(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === false) {
            return [];
        }

        return ($this->serialiseObject(value: $value) ?? (array) $value);
    }//end toArrayWithCast()

    /**
     * Try the two serialisation methods OpenRegister entities expose.
     *
     * @param mixed $value The candidate object.
     *
     * @return array<string, mixed>|null The serialised array, or null when the
     *                                   value is not a serialisable object.
     */
    private function serialiseObject(mixed $value): ?array
    {
        if (is_object($value) === false) {
            return null;
        }

        if (method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (method_exists($value, 'toArray') === true) {
            $converted = $value->toArray();
            if (is_array($converted) === true) {
                return $converted;
            }
        }

        return null;
    }//end serialiseObject()
}//end class
