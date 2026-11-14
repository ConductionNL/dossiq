<?php

/**
 * Contract of WooDeliveredSetVerifier
 *
 * The public surface of WooDeliveredSetVerifier that other components call, so they depend
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
 * Contract of WooDeliveredSetVerifier.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooDeliveredSetVerifierInterface {

	/**
	 * The stored set, or null when it does not exist.
	 *
	 * @param string $setId The set.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	public function find(string $setId): ?array;

	/**
	 * Verify a set against the files as they are now.
	 *
	 * @param array<string, mixed> $set The stored set.
	 *
	 * @return array{verified: bool, setHash: array{expected: string, actual: string}, items: array<int, array<string, string>>}
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	public function verify(array $set): array;
}//end interface
