<?php

/**
 * Contract of ZgwJwtValidator
 *
 * The public surface of ZgwJwtValidator that other components call, so they depend
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

use OCA\Dossiq\Service\ZgwAuthValidationException;

/**
 * Contract of ZgwJwtValidator.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwJwtValidatorInterface {

	/**
	 * Validate a JWT bearer token from an Authorization header.
	 *
	 * On success the matching Consumer's Nextcloud user is set on the session
	 * (mirroring OpenRegister's authorizeJwt behaviour) so downstream object
	 * operations run with the correct identity.
	 *
	 * @param string $authorization The full Authorization header value
	 *
	 * @return void
	 *
	 * @throws ZgwAuthValidationException If the token is missing, malformed,
	 *                                    or the signature/payload is invalid.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function validate(string $authorization): void;
}//end interface
