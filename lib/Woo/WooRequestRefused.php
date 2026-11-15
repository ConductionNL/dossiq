<?php

/**
 * Dossiq Woo request refusal
 *
 * Why a Woo request was not opened, as a code the caller maps to an answer:
 * `invalid` is a request that cannot be used (400), `not_found` is a dossier
 * that does not exist or is someone else's (404, the two are one answer so
 * nobody can probe which ids exist), `unavailable` is a missing register or
 * case type (503).
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use RuntimeException;

/**
 * A Woo request that was refused, with the reason as a code.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */
class WooRequestRefused extends RuntimeException {

	/**
	 * The request cannot be used.
	 */
	public const INVALID = 'invalid';

	/**
	 * The dossier does not exist or is not the resident's.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * The register or the Woo case type is missing.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants above; also the exception message.
	 * @param string $detail What was wrong, for the log and the 400 answer. It
	 *                       is the English catalogue sentence; the portal route
	 *                       translates it for the resident.
	 * @param string $field  The form field the refusal is about, or '' when it
	 *                       is about the request as a whole. A portal shows the
	 *                       sentence on that field.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002
	 */
	public function __construct(
		private readonly string $reason,
		private readonly string $detail = '',
		private readonly string $field = '',
	) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * The form field the refusal is about, or '' for the request as a whole.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002
	 */
	public function getField(): string {
		return $this->field;
	}//end getField()

	/**
	 * The reason code.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * What was wrong, in one sentence.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function getDetail(): string {
		return $this->detail;
	}//end getDetail()
}//end class
