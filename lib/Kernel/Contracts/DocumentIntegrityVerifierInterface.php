<?php

/**
 * Contract of DocumentIntegrityVerifier
 *
 * The public surface of DocumentIntegrityVerifier that other components call, so they depend
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
 * Contract of DocumentIntegrityVerifier.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentIntegrityVerifierInterface {

	/**
	 * Check the next batch of documents and record each outcome.
	 *
	 * @param int $batch How many documents one run reads.
	 *
	 * @return int How many documents were checked or got their first hash.
	 *
	 * @spec openspec/specs/document-integrity-check/spec.md#requirement-stored-checksums-are-checked-again-and-the-check-is-recorded
	 */
	public function verifyNext(int $batch = 200): int;
}//end interface
