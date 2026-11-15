<?php

/**
 * The parent whose sub-cases are detached, and where those sub-cases are stored.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The parent whose sub-cases are detached, and where those sub-cases are stored.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */
final class SubCaseUnlinkTarget {

	/**
	 * Constructor.
	 *
	 * @param string $parentCaseUuid Parent UUID.
	 * @param object $objectService  OpenRegister ObjectService.
	 * @param string $register       Register id.
	 * @param string $schema         Case schema id.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function __construct(
		public readonly string $parentCaseUuid,
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
