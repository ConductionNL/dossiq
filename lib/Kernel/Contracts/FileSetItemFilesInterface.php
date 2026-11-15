<?php

/**
 * Contract of FileSetItemFiles
 *
 * The public surface of FileSetItemFiles that other components call, so they depend
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
 * Contract of FileSetItemFiles.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FileSetItemFilesInterface {

	/**
	 * Name and type of both files of one item, or null when the set has no such item.
	 *
	 * @param array<string, mixed> $set   The stored set.
	 * @param int                  $index The item's position in `items`.
	 *
	 * @return array<string, array{fileName: string, mimeType: string, readable: bool}>|null
	 *
	 * @spec openspec/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
	 */
	public function describe(array $set, int $index): ?array;

	/**
	 * The bytes, name and type of one side of one item, or null when there is nothing to read.
	 *
	 * @param array<string, mixed> $set   The stored set.
	 * @param int                  $index The item's position in `items`.
	 * @param string               $side  `original` or `delivered`.
	 *
	 * @return array{bytes: string, fileName: string, mimeType: string}|null
	 *
	 * @spec openspec/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
	 */
	public function read(array $set, int $index, string $side): ?array;
}//end interface
