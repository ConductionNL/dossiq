<?php

/**
 * Contract of WooGatherAdd
 *
 * The public surface of WooGatherAdd that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of WooGatherAdd.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooGatherAddInterface {

	/**
	 * Add every pick to the case, each on its own.
	 *
	 * @param string                           $caseId The Woo case uuid.
	 * @param array<int, array<string, mixed>> $picks  Each `{source, key, location?}`: `key` is the file id,
	 *                                                 the other case's document uuid, or integriq's handle.
	 * @param string                           $terms  The search terms the picks were found with.
	 * @param IUser                            $user   The caller.
	 * @param array<int, string>               $custodians The recorded plan's custodians; every pick names one.
	 *
	 * @return array<int, array<string, mixed>> One `{key, source, status, documentId, reason, message}` per pick;
	 *         `status` is added, excluded (recorded as an exclusion, such as a duplicate) or refused.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-every-gathered-document-records-where-it-was-found-req-woo-014
	 */
	public function addPicks(string $caseId, array $picks, string $terms, IUser $user, array $custodians): array;
}//end interface
