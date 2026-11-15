<?php

/**
 * Dossiq TermAsk
 *
 * The case type and case values a term is resolved for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

/**
 * One question to the term resolution: which zaaktype, and the case's own values.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md#requirement-a-term-resolves-from-the-organisation-the-service-and-the-priority-req-tcf-02
 */
final class TermAsk {
	/**
	 * Constructor.
	 *
	 * @param string               $caseType The zaaktype slug.
	 * @param array<string, mixed> $context  The case's organisation, service and priority.
	 */
	public function __construct(
		public readonly string $caseType,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
