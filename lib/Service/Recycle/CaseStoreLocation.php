<?php

/**
 * Where the live cases are stored: OpenRegister's object service, the
 * register and the case schema.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

/**
 * The object service, register and schema a live case is read from.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
final class CaseStoreLocation {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The case schema.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
