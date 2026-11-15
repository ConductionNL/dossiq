<?php

/**
 * What a case type declares about acknowledging receipt: the resolver, the store and the declaration.
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
 * @spec openspec/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * What a case type declares about acknowledging receipt: the resolver, the store and the declaration.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */
final class AcknowledgementDeclaration {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver        $caseTypeResolver The effective case type, parents included.
	 * @param CaseTypeStore           $store            Reference resolution for the case's case type.
	 * @param CaseTypeAcknowledgement $declaration      What the case type declares about confirming receipt.
	 */
	public function __construct(
		public readonly CaseTypeResolver $caseTypeResolver,
		public readonly CaseTypeStore $store,
		public readonly CaseTypeAcknowledgement $declaration,
	) {
	}//end __construct()
}//end class
