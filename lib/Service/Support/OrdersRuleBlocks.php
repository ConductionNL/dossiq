<?php

/**
 * Dossiq OrdersRuleBlocks trait.
 *
 * Puts a gathered block of declared field rules in the order OpenRegister
 * lists the rule kinds. The using class provides a `RULES` constant: the
 * kinds, in OpenRegister's order.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/security-hardening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Orders a block of declared field rules by kind.
 *
 * @spec openspec/specs/security-hardening/spec.md
 */
trait OrdersRuleBlocks {
	/**
	 * The block with its kinds in the order OpenRegister lists them.
	 *
	 * Order changes nothing about what is enforced. It makes the block
	 * DIFFABLE, which is what keeps the projector from rewriting the schema on
	 * every publish over a difference that is not one.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $block The gathered block.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The block, ordered.
	 */
	private function inPublishedOrder(array $block): array {
		$ordered = [];
		foreach (self::RULES as $kind) {
			if (($block[$kind] ?? []) !== []) {
				$ordered[$kind] = $block[$kind];
			}
		}

		return $ordered;
	}//end inPublishedOrder()
}//end trait
