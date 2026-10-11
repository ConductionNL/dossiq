<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Permit;

use RuntimeException;

/**
 * Why a change to a permit was not opened, as a code the caller maps to an answer.
 *
 * `invalid` is a request that cannot be used (400), `not_found` is a permit
 * that does not exist or is someone else's (404, one answer so nobody can probe
 * which ids exist), `not_active` is a suspended or revoked permit (409), and
 * `unavailable` is a missing register or case type (503).
 *
 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
 */
class PermitChangeRefused extends RuntimeException {
	/**
	 * The request cannot be used.
	 */
	public const INVALID = 'invalid';

	/**
	 * The permit does not exist or is not the resident's.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * The permit is suspended or revoked.
	 */
	public const NOT_ACTIVE = 'not_active';

	/**
	 * The register or the change case type is missing.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * @param string $reason One of the constants above; also the exception message.
	 * @param string $detail A sentence for the log and the caller.
	 */
	public function __construct(private readonly string $reason, private readonly string $detail) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * @return string The reason code.
	 *
	 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
	 */
	public function getDetail(): string {
		return $this->detail;
	}//end getDetail()
}//end class
