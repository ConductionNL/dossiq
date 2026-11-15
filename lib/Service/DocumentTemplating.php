<?php

/**
 * What a generated document is made with: the template library, the filer and the retired template syntax.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;

/**
 * What a generated document is made with: the template library, the filer and the retired template syntax.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */
final class DocumentTemplating {
	/**
	 * Constructor.
	 *
	 * @param TemplateLibraryService $templates The template library.
	 * @param GeneratedDocumentFiler $filer     Files the document in the dossier.
	 * @param RetiredTemplateSyntax  $syntax    Rewrites the library template for Filinq.
	 */
	public function __construct(
		public readonly TemplateLibraryService $templates,
		public readonly GeneratedDocumentFiler $filer,
		public readonly RetiredTemplateSyntax $syntax,
	) {
	}//end __construct()
}//end class
