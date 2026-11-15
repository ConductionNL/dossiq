<?php

/**
 * Contract of DocumentCopier
 *
 * The public surface of DocumentCopier that other components call, so they depend
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
 * Contract of DocumentCopier.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentCopierInterface {

	/**
	 * Copy each document to the target case.
	 *
	 * @param IUser         $user         The caller.
	 * @param array<mixed>  $ids          The document (informatieobject) ids.
	 * @param string        $targetCaseId The case the copies go to.
	 *
	 * @return list<array<string, mixed>> Per id: `id`, `success`, and `copyId` or `error`.
	 *
	 * @spec openspec/specs/documents-copy-to-case/spec.md#requirement-documents-are-copied-to-a-case-the-caller-may-add-to
	 */
	public function copyAll(IUser $user, array $ids, string $targetCaseId): array;
}//end interface
