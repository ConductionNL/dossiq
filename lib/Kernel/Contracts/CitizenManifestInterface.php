<?php

/**
 * Contract of CitizenManifest
 *
 * The public surface of CitizenManifest that other components call, so they depend
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
 * Contract of CitizenManifest.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenManifestInterface {

	/**
	 * The field the requester's files travel under (portaliq row-action-carries-files).
	 */
	public const ANSWER_FILES_FIELD = 'bijlagen';

	/**
	 * At most this many files with one answer.
	 */
	public const ANSWER_FILES_MAX = 5;

	/**
	 * At most this many bytes per file (10 MiB).
	 */
	public const ANSWER_FILES_MAX_BYTES = 10485760;
}//end interface
