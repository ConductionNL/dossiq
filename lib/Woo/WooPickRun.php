<?php

/**
 * One pick while WooGatherAdd adds it to a Woo case.
 *
 * The case, the search terms, the caller and the plan's custodians it was
 * picked under, the pick itself, and, as the pick goes, where it was found
 * and which document of the case already holds the same bytes.
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
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCP\IUser;

/**
 * The state of one pick, built and read by WooGatherAdd only.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 */
final class WooPickRun {
	/**
	 * Where the pick was found, set once its custodian is accepted.
	 *
	 * @var array<string, mixed>
	 */
	public array $provenance = [];

	/**
	 * The document of the case that holds the same bytes, '' when none.
	 *
	 * @var string
	 */
	public string $duplicateOf = '';

	/**
	 * Constructor.
	 *
	 * @param string               $caseId     The Woo case uuid.
	 * @param string               $terms      The search terms the pick was found with.
	 * @param IUser                $user       The caller.
	 * @param array<int, string>   $custodians The recorded plan's custodians.
	 * @param array<string, mixed> $pick       The pick: `{source, key, custodian, location?}`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $terms,
		public readonly IUser $user,
		public readonly array $custodians,
		public readonly array $pick,
	) {
	}//end __construct()

	/**
	 * The pick's source.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	public function source(): string {
		return (string)($this->pick['source'] ?? '');
	}//end source()

	/**
	 * The pick's key: a file id, another case's document uuid or integriq's handle.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	public function key(): string {
		return trim((string)($this->pick['key'] ?? ''));
	}//end key()

	/**
	 * Whose files the pick comes from.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	public function custodian(): string {
		return trim((string)($this->pick['custodian'] ?? ''));
	}//end custodian()

	/**
	 * Where in the source the pick was found, '' when not given.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	public function location(): string {
		return (string)($this->pick['location'] ?? '');
	}//end location()
}//end class
