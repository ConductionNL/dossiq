<?php

/**
 * Declaration-only stub of OpenRegister's #[McpTool] attribute.
 *
 * Mirrors `OCA\OpenRegister\Mcp\Attribute\McpTool` on openregister
 * origin/development verbatim (constructor and properties), so dossiq's unit
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

if (class_exists(McpTool::class, false) === false) {
	/**
	 * Stub of the method attribute that marks a service method as an MCP tool.
	 */
	#[Attribute(Attribute::TARGET_METHOD)]
	final class McpTool {
		/**
		 * Constructor.
		 *
		 * @param string|null $name            Local tool name.
		 * @param string|null $description     Agent-facing description.
		 * @param bool|null   $readOnlyHint    MCP hint.
		 * @param bool|null   $destructiveHint MCP hint.
		 * @param bool|null   $idempotentHint  MCP hint.
		 * @param string|null $scope           read, create, update or delete.
		 * @param string|null $subject         The thing the tool acts on.
		 * @param string|null $action          The verb the tool performs.
		 * @param string|null $reach           self, user, instance or external.
		 * @param array<string, bool|int|float|string> $annotations Free-form marks a consumer reads (REQ-ATTR-007).
		 */
		public function __construct(
			public readonly ?string $name = null,
			public readonly ?string $description = null,
			public readonly ?bool $readOnlyHint = null,
			public readonly ?bool $destructiveHint = null,
			public readonly ?bool $idempotentHint = null,
			public readonly ?string $scope = null,
			public readonly ?string $subject = null,
			public readonly ?string $action = null,
			public readonly ?string $reach = null,
			public readonly array $annotations = [],
		) {
		}//end __construct()
	}//end class
}//end if
