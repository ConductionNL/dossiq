<?php

/**
 * Dossiq sub-case store.
 *
 * Where a derive reads the parent case and saves the child: OpenRegister's
 * object service, the register and the case schema, and the parent the
 * child is derived from.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Deelzaak
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
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Deelzaak;

/**
 * The object service, register, schema and parent case of one derive.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */
final class SubCaseStore {

	/**
	 * Constructor.
	 *
	 * @param object $objectService  OpenRegister's object service.
	 * @param string $register       The register.
	 * @param string $schema         The case schema.
	 * @param string $parentCaseUuid The parent case.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $parentCaseUuid,
	) {
	}//end __construct()
}//end class
