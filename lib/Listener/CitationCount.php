<?php

/**
 * Dossiq citation count.
 *
 * What counting the rows that cite one Woo refusal ground shares between its steps.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-one-hierarchical-list-of-grounds-in-dossiqs-register-req-wrg-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * One count: where the citing rows live, and the ground's code.
 *
 * Built by {@see WooRefusalGroundDeleteGuard} only; it never leaves it.
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-one-hierarchical-list-of-grounds-in-dossiqs-register-req-wrg-002
 */
final class CitationCount {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The ground's code.
	 *
	 * @var string
	 */
	public string $code = '';
}//end class
