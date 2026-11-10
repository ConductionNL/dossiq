<?php

/**
 * Dossiq GeneratedDocumentRun.
 *
 * One document generated from a library template for a case while it is
 * being made: the case, the template it comes from, the name and type it is
 * filed under, the template text in Filinq's syntax and the metadata it is
 * filed with.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one generated document, built and read by
 * CaseDocumentGenerationService only.
 *
 * @spec openspec/specs/template-library/spec.md
 */
final class GeneratedDocumentRun {
	/**
	 * The case the document is for.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * The case, as the render context, always carrying its id.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $case;

	/**
	 * The library template's id.
	 *
	 * @var string
	 */
	public string $templateId = '';

	/**
	 * The document type it is filed under.
	 *
	 * @var string
	 */
	public string $documentType = '';

	/**
	 * The document's name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The template, in Filinq's syntax.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The metadata the document is filed with.
	 *
	 * @var array<string, mixed>
	 */
	public array $metadata = [];

	/**
	 * Start a document for a case. A case read through a projection that
	 * drops its id would otherwise file the document nowhere, so the id the
	 * case was asked for stands in for a missing one.
	 *
	 * @param string               $caseId The case the document is for.
	 * @param array<string, mixed> $case   The case as loaded.
	 */
	public function __construct(string $caseId, array $case) {
		$case['id'] = ($case['id'] ?? $caseId);
		$this->caseId = $caseId;
		$this->case = $case;
	}//end __construct()
}//end class
