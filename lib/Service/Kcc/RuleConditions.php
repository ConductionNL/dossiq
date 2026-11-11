<?php

/**
 * The conditions of one KCC routing rule, gathered for its decision-table row
 *
 * The equality columns with their values, the keyword and regex columns,
 * and the time-of-day window narrowed by every time condition of the rule:
 * what RoutingTableEvaluator collects from a rule before it writes the
 * rule's cells.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-routing-onto-or-decision-tables/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * The gathered conditions of one rule; it never leaves the evaluator that built it.
 *
 * @spec openspec/changes/kcc-routing-onto-or-decision-tables/specs/kcc-routing/spec.md
 */
final class RuleConditions {

	/**
	 * The value per equality column.
	 *
	 * @var array<string, string>
	 */
	public array $equalities = [];

	/**
	 * The keyword and regex columns, by name, with what they test.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $booleans = [];

	/**
	 * The earliest minute of the day the rule matches, or null.
	 *
	 * @var integer|null
	 */
	public ?int $lowerBound = null;

	/**
	 * The latest minute of the day the rule matches, or null.
	 *
	 * @var integer|null
	 */
	public ?int $upperBound = null;
}//end class
