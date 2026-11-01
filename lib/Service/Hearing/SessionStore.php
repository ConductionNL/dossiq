<?php

/**
 * Dossiq SessionStore value object.
 *
 * Where hearing sessions are read and written: the object service with the register and schema it is called with.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Hearing
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Hearing;

/**
 * The object service, register and schema one hearing operation uses.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class SessionStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService  OpenRegister object service.
	 * @param string $register       The register.
	 * @param string $schema         The hearing session schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()

	/**
	 * Whether both the register and the schema are set.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function isConfigured(): bool {
		return $this->register !== '' && $this->schema !== '';
	}//end isConfigured()
}//end class
