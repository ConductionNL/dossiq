<?php

/**
 * Declaration-only stub of OpenRegister's #[McpToolTitle] attribute.
 *
 * Mirrors `OCA\OpenRegister\Mcp\Attribute\McpToolTitle` on openregister
 * origin/development verbatim (constructor and property), so dossiq's unit
 * tests and analysers can read the attribute when OpenRegister is absent. The
 * real class wins whenever OpenRegister is installed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Mcp\Attribute
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

namespace OCA\OpenRegister\Mcp\Attribute;

use Attribute;

if (class_exists(McpToolTitle::class, false) === false) {
	/**
	 * Stub of the attribute that gives an #[McpTool] method a human title.
	 */
	#[Attribute(Attribute::TARGET_METHOD)]
	final class McpToolTitle {
		/**
		 * Constructor.
		 *
		 * @param string $title The title a client shows for the tool.
		 */
		public function __construct(
			public readonly string $title,
		) {
		}//end __construct()
	}//end class
}//end if
