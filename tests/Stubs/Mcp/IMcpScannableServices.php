<?php

/**
 * Declaration-only stub of OpenRegister's IMcpScannableServices.
 *
 * Mirrors `OCA\OpenRegister\Mcp\IMcpScannableServices` on openregister
 * origin/development. The real interface wins whenever OpenRegister is installed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Mcp
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

if (interface_exists(IMcpScannableServices::class, false) === false) {
	/**
	 * The per-app list of service classes OpenRegister scans for #[McpTool].
	 */
	interface IMcpScannableServices {
		/**
		 * The app's own service classes eligible for #[McpTool] reflection.
		 *
		 * @return list<class-string>
		 */
		public function getScannableServiceClasses(): array;
	}//end interface
}//end if
