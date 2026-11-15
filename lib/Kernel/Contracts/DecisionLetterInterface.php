<?php

/**
 * Contract of DecisionLetter
 *
 * The public surface of DecisionLetter that other components call, so they depend
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

/**
 * Contract of DecisionLetter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionLetterInterface {

	/**
	 * The declared letter for a purpose and the case's newest document of its type.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $purpose The declared purpose, for example `decision`.
	 *
	 * @return array{
	 *     declaration: array{
	 *         title: string, documentType: string, templateSlug: string, format: string, signing: string, delivery: string
	 *     }|null,
	 *     document: array<string, mixed>|null,
	 *     empty: bool
	 * }
	 *         `declaration` is null when the case type declares no letter for the purpose; `empty` is true
	 *         while the case holds no such letter, so a facts card can show its empty text (`emptyWhen`).
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function forCase(string $caseId, string $purpose): array;

	/**
	 * Link a recorded decision to its letter: the chosen one, else the one drafted with it.
	 *
	 * The chosen letter was checked with {@see assertOnCase()} before the
	 * decision was written; a drafted one was filed on the case by the draft.
	 *
	 * @param array<string, mixed> $result The assembled decision (`decisionId`, `draft`).
	 * @param string               $letter The chosen informatieobject uuid, or empty.
	 *
	 * @return string The linked letter, or empty when there is none.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function linkDecision(array $result, string $letter): string;

	/**
	 * Refuse a letter that is not a document of the case.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $documentId The informatieobject uuid; empty means no letter was chosen.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the document is not on the case.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function assertOnCase(string $caseId, string $documentId): void;
}//end interface
