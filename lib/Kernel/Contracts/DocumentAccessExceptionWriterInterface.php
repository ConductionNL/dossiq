<?php

/**
 * Contract of DocumentAccessExceptionWriter
 *
 * The public surface of DocumentAccessExceptionWriter that other components call, so they depend
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

use InvalidArgumentException;
use OCP\IUser;
use RuntimeException;

/**
 * Contract of DocumentAccessExceptionWriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentAccessExceptionWriterInterface {

	/**
	 * Remove the exception of a record.
	 *
	 * @param IUser                $user   Who removes it.
	 * @param array<string, mixed> $record The informatieobject, with `id`.
	 * @param string               $reason Why, may be empty.
	 *
	 * @return bool True when there was an exception to remove.
	 *
	 * @throws RuntimeException When the file cannot be freed, or the record cannot be saved.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function remove(IUser $user, array $record, string $reason = ''): bool;

	/**
	 * Set or change the exception of a record.
	 *
	 * @param IUser                                                           $user      Who sets it.
	 * @param array<string, mixed>                                            $record    The informatieobject, with `id`.
	 * @param array{users: list<string>, groups: list<string>, reason: string} $exception The validated exception.
	 *
	 * @return array<string, mixed> The exception as stored.
	 *
	 * @throws RuntimeException When the file cannot be restricted, or the record cannot be saved.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function set(IUser $user, array $record, array $exception): array;

	/**
	 * The exception asked for, checked: at least one existing person or group, and a reason.
	 *
	 * @param array<string, mixed> $input `users`, `groups`, `reason`.
	 *
	 * @return array{users: list<string>, groups: list<string>, reason: string} The exception.
	 *
	 * @throws InvalidArgumentException When it names nobody, names an unknown person or group, or has no reason.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function validated(array $input): array;
}//end interface
