<?php

/**
 * Contract of PortalAssertionVerifier
 *
 * The public surface of PortalAssertionVerifier that other components call, so they depend
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
 * Contract of PortalAssertionVerifier.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAssertionVerifierInterface {

	/**
	 * The header portaliq attaches the assertion to.
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The verified claims of an assertion, or null.
	 *
	 * Null unless: three non-empty segments, header `alg` exactly HS256, the
	 * HMAC matches, the claims decode, `use` is `assertion`, `iss` is
	 * `portaliq`, `exp` is in the future, `iat` is not, and `sub` is a
	 * non-empty string. Never throws.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
	 */
	public function verify(string $jwt): ?array;
}//end interface
