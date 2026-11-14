<?php

/**
 * Contract of ZtcTypeNameResolver
 *
 * The public surface of ZtcTypeNameResolver that other components call, so they depend
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
 * Contract of ZtcTypeNameResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZtcTypeNameResolverInterface {

	/**
	 * Resolve informatieobjecttype by omschrijving when not a UUID/URL (ztc-010m).
	 *
	 * The ZGW standard allows referencing an IOT by omschrijving in ZIOT creation.
	 * This method looks up the IOT by omschrijving and replaces it with its UUID.
	 *
	 * @param array $body The request body (modified in-place via cached body)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function resolveInformatieobjecttype(array $body): void;
}//end interface
