<?php

/**
 * The register and schema a publication object is read from or written to.
 *
 * Built by each public method of `OpenCatalogiApiClient` and handed through its private read and write
 * steps.
 *
 * @category Publication
 * @package  OCA\Dossiq\Service\Publication
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

namespace OCA\Dossiq\Service\Publication;

/**
 * The register and schema a publication object is read from or written to.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
final class PublicationTarget {

	/**
	 * Constructor.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
