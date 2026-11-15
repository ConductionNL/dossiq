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
	 * The consumer a JWT bearer token belongs to, verified, without binding its user.
	 *
	 * Decodes the token, finds the consumer named by its issuer, checks the
	 * signature against that consumer's shared secret and checks `iat`/`exp`.
	 * The caller decides what to do with the consumer; ApiCredentialResolver
	 * binds its user once, whatever the credential.
	 *
	 * @param string $authorization The full Authorization header value
	 *
	 * @return object The OpenRegister consumer
	 *
	 * @throws ZgwAuthValidationException If the token is missing, malformed,
	 *                                    or the signature/payload is invalid.
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-the-caller-may-present-a-jwt-an-api-key-or-a-client-certificate-req-paa-02
	 */
	public function consumerFor(string $authorization): object;
}//end interface
