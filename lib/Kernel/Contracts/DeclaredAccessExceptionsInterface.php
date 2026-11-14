<?php

/**
 * Contract of DeclaredAccessExceptions
 *
 * The public surface of DeclaredAccessExceptions that other components call, so they depend
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
 * Contract of DeclaredAccessExceptions.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeclaredAccessExceptionsInterface {

	/**
	 * Apply the exception a stored document carries, when its history, readers or file are not in step with it.
	 *
	 * @param array<string, mixed>      $record The document as stored, with `id`.
	 * @param array<string, mixed>|null $before The document before this write, null on a create.
	 *
	 * @return bool True when something was applied and the record saved.
	 *
	 * @throws RuntimeException When the file cannot be restricted; nothing is saved then, so the next write tries again.
	 *
	 * @spec openspec/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
	 */
	public function apply(array $record, ?array $before): bool;
}//end interface
