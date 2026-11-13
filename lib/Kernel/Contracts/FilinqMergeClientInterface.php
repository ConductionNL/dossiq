<?php

/**
 * Contract of FilinqMergeClient
 *
 * The public surface of FilinqMergeClient that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of FilinqMergeClient.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FilinqMergeClientInterface {

	/**
	 * Merge the files, in this order, into one PDF beside the first.
	 *
	 * @param list<int> $fileIds The Nextcloud file ids.
	 * @param string    $name    The PDF's name, '' for filinq's own.
	 * @param string    $caseId  The case the merge was started from.
	 *
	 * @return array<string, mixed> Filinq's finished job (`status`, `resultFileId`).
	 *
	 * @throws RuntimeException When filinq is absent (`filinq_unavailable`) or refuses.
	 *
	 * @spec openspec/specs/documents-merge-pdf/spec.md#requirement-a-cases-documents-are-merged-into-one-pdf-by-filinq
	 */
	public function merge(array $fileIds, string $name, string $caseId): array;
}//end interface
