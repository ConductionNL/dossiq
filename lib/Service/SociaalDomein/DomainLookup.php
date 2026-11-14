<?php

/**
 * One social domain asked whether it holds anything open about a person.
 *
 * Built by `CrossDomainExistence` for each domain it asks, and handed through the steps that find the
 * domain's contact: the domain, its declaration and the person's BSN.
 *
 * @category SociaalDomein
 * @package  OCA\Dossiq\Service\SociaalDomein
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

namespace OCA\Dossiq\Service\SociaalDomein;

/**
 * One social domain asked whether it holds anything open about a person.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
 */
final class DomainLookup {

	/**
	 * Constructor.
	 *
	 * @param string                                $domain      The domain.
	 * @param array{schema: string, bsnKey: string} $declaration The domain's declaration.
	 * @param string                                $bsn         The person looked up.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $domain,
		public readonly array $declaration,
		public readonly string $bsn,
	) {
	}//end __construct()
}//end class
