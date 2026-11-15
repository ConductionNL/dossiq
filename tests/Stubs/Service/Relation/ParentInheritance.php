<?php

/**
 * Test stub for OCA\OpenRegister\Service\Relation\ParentInheritance.
 *
 * The value object ObjectRelationService::inherit() takes. The real class
 * types `$parent` as ObjectEntity; this stub accepts any object so the
 * deriver's tests can hand it the light parent doubles they already use.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

if (class_exists('\\OCA\\OpenRegister\\Service\\Relation\\ParentInheritance', false) === false) {
    /**
     * The parent, the child so far, and what the child takes from it.
     */
    final class ParentInheritance {

        /**
         * @param object               $parent    The parent object.
         * @param array<string, mixed> $childData The child so far.
         * @param array<string, mixed> $inherits  Role to property name.
         */
        public function __construct(
            public readonly object $parent,
            public readonly array $childData,
            public readonly array $inherits,
        ) {
        }
    }
}
