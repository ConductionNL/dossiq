<?php

/**
 * Dossiq RefusalGroundMapping.
 *
 * One schema while MapWooRefusalGroundCodes maps its stored refusal grounds:
 * where it lives, the settled codes it is mapped onto, and the tally.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * The mapping of one schema, built and read by MapWooRefusalGroundCodes only.
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
 */
final class RefusalGroundMapping {
	/**
	 * OpenRegister's object service.
	 *
	 * @var object
	 */
	public readonly object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The configuration key the schema is set under.
	 *
	 * @var string
	 */
	public string $configKey = '';

	/**
	 * The schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The codes on the settled list.
	 *
	 * @var array<int, string>
	 */
	public array $settled = [];

	/**
	 * What the mapping did.
	 *
	 * @var array{mapped: int, failed: int, unmapped: array<string, array<int, string>>}
	 */
	public array $tally = ['mapped' => 0, 'failed' => 0, 'unmapped' => []];

	/**
	 * Start mapping a schema in one register.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 */
	public function __construct(object $objectService, string $register) {
		$this->objectService = $objectService;
		$this->register = $register;
	}//end __construct()
}//end class
