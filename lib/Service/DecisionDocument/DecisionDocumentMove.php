<?php

/**
 * One requested move of a decision document: its kind, the document, who moves it and when.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use DateTimeImmutable;

/**
 * One requested move of a decision document: its kind, the document, who moves it and when.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class DecisionDocumentMove {
	/**
	 * Constructor.
	 *
	 * @param DecisionDocumentDefinition $definition The kind's definition.
	 * @param array<string, mixed>       $document   The document as stored.
	 * @param string                     $actor      The acting user.
	 * @param DateTimeImmutable          $moment     The moment of the move.
	 */
	public function __construct(
		public readonly DecisionDocumentDefinition $definition,
		public readonly array $document,
		public readonly string $actor,
		public readonly DateTimeImmutable $moment,
	) {
	}//end __construct()
}//end class
