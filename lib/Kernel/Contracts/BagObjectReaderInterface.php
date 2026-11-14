<?php

/**
 * Contract of BagObjectReader
 *
 * The public surface of BagObjectReader that other components call, so they depend
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
 * Contract of BagObjectReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BagObjectReaderInterface {

	/**
	 * Integriq is not installed on this instance.
	 */
	public const REASON_NO_INTEGRIQ = 'integriq-not-installed';

	/**
	 * Integriq is installed, in a version without the BAG object read.
	 */
	public const REASON_NO_READER = 'integriq-has-no-bag-read';

	/**
	 * Whether the BAG holds an object.
	 *
	 * @param string $type `pand`, `verblijfsobject` or `nummeraanduiding`.
	 * @param string $identificatie The 16-digit BAG identificatie.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool}
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
	 */
	public function read(string $type, string $identificatie): array;
}//end interface
