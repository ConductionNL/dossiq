<?php

/**
 * Contract of GeneratedDocumentFiler
 *
 * The public surface of GeneratedDocumentFiler that other components call, so they depend
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
 * Contract of GeneratedDocumentFiler.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GeneratedDocumentFilerInterface {

	/**
	 * The metadata key that marks a request whose caller files the document itself.
	 *
	 * The Generate document button files the document so it can answer with
	 * the informatieobject it made; the listener leaves such a document alone,
	 * or the case would get it twice.
	 *
	 * @var string
	 */
	public const FILED_BY_CALLER = 'dossiqFiledByCaller';

	/**
	 * File one generated file on a case.
	 *
	 * @param string               $caseId   The case.
	 * @param int                  $fileId   The file Filinq stored.
	 * @param string               $title    The document's title.
	 * @param string               $mime     The file's type.
	 * @param array<string, mixed> $metadata What the request passed through.
	 *
	 * @return array<string, mixed> The informatieobject summary.
	 *
	 * @throws RuntimeException When the metadata names no type or the file cannot be read.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function file(string $caseId, int $fileId, string $title, string $mime, array $metadata): array;

	/**
	 * Whether metadata asks for the document to be filed on the case at all.
	 *
	 * @param array<string, mixed> $metadata The request's metadata.
	 *
	 * @return bool True when it names a document type.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function wantsFiling(array $metadata): bool;
}//end interface
