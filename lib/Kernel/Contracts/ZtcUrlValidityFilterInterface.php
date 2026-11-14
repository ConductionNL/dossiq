<?php

/**
 * Contract of ZtcUrlValidityFilter
 *
 * The public surface of ZtcUrlValidityFilter that other components call, so they depend
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
 * Contract of ZtcUrlValidityFilter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZtcUrlValidityFilterInterface {

	/**
	 * Drop every URL in the configured list fields that does not point at a published,
	 * currently valid object.
	 *
	 * @param array<string, array{schemaKey: string, nested: bool}> $fieldConfigs List field => target
	 * @param array $data The outbound-mapped object
	 * @param string $today Today's date in Y-m-d format
	 *
	 * @return array The object with only valid URLs left
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function filter(array $fieldConfigs, array $data, string $today): array;
}//end interface
