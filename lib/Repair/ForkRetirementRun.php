<?php

/**
 * Dossiq ForkRetirementRun.
 *
 * One retirement of the `dossiq.demo` schema fork while it runs: the
 * OpenRegister schema mapper and deletion service it works through, the
 * schema ids a register still links, and what it retired and spared so far.
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * The state of one fork retirement, built and read by
 * RepairDemoDataSchemaFork only.
 *
 * @spec exclude No canonical spec covers repairing the damage of a defective
 *  demo import. Pointing this at the first-time-setup spec would report
 *  conformance to a requirement that says nothing about the fork.
 */
final class ForkRetirementRun {
	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var object
	 */
	public readonly object $schemaMapper;

	/**
	 * OpenRegister's schema deletion service.
	 *
	 * @var object
	 */
	public readonly object $deletion;

	/**
	 * The schema ids any register still links.
	 *
	 * @var array<int, int>
	 */
	public array $linked = [];

	/**
	 * How many forked schemas were retired.
	 *
	 * @var integer
	 */
	public int $retired = 0;

	/**
	 * How many demo objects went with them.
	 *
	 * @var integer
	 */
	public int $objects = 0;

	/**
	 * The schemas kept, by slug, with the reason.
	 *
	 * @var array<string, string>
	 */
	public array $spared = [];

	/**
	 * Start a retirement through the two OpenRegister collaborators.
	 *
	 * @param object $schemaMapper OpenRegister's schema mapper.
	 * @param object $deletion     OpenRegister's schema deletion service.
	 */
	public function __construct(object $schemaMapper, object $deletion) {
		$this->schemaMapper = $schemaMapper;
		$this->deletion = $deletion;
	}//end __construct()
}//end class
