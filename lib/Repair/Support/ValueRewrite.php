<?php

/**
 * One stored value a repair step moves back: schema, property, wrong value, right value.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

/**
 * A wrong value on one schema's property and the value it should hold.
 *
 * @spec exclude Corrective data migration value carrier for vocabulary repair steps.
 */
final class ValueRewrite {
	/**
	 * The schema slug.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The property to rewrite.
	 *
	 * @var string
	 */
	public string $property = '';

	/**
	 * The value the bad rename produced.
	 *
	 * @var string
	 */
	public string $wrong = '';

	/**
	 * The value the schema declares.
	 *
	 * @var string
	 */
	public string $right = '';
}//end class
