<?php

/**
 * Contract of WooDeliveredSetWriter
 *
 * The public surface of WooDeliveredSetWriter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of WooDeliveredSetWriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooDeliveredSetWriterInterface {

	/**
	 * The app-config key naming the set schema.
	 */
	public const CONFIG_KEY = 'woo_delivered_set_schema';

	/**
	 * The set schema's slug, used when the config key is not set yet.
	 */
	public const SCHEMA_SLUG = 'wooDeliveredSet';

	/**
	 * A set whose publication exists.
	 */
	public const STATUS_FROZEN = 'frozen';

	/**
	 * The frozen sets of a case, oldest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function frozenSets(string $caseId): array;
}//end interface
