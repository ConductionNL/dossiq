<?php

/**
 * Dossiq Woo publication summary: the line under the title of a published decision.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * The line a resident reads under the title of the published decision.
 *
 * The one place the wording lives, so the decision a new publication carries
 * and a summary the repair step rewrites cannot come to read differently.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
final class WooPublicationSummary {
	/**
	 * The summary for a request, named by the case's title and never by an id.
	 *
	 * @param string $caseTitle The case's title, or '' when it has none.
	 *
	 * @return string The summary.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public static function of(string $caseTitle): string {
		$title = trim($caseTitle);
		if ($title === '') {
			return 'Besluit op een Woo-verzoek';
		}

		return 'Besluit op het Woo-verzoek "' . $title . '"';
	}//end of()
}//end class
