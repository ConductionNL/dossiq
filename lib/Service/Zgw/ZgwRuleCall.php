<?php

/**
 * One ZGW request as the rules dispatcher routes it: resource, action, body and the stored object.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * A request the business rules are asked about.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZgwRuleCall {
	/**
	 * Constructor.
	 *
	 * @param string     $resource       The ZGW resource name.
	 * @param string     $action         The action.
	 * @param array      $body           The request body.
	 * @param array|null $existingObject The existing object data.
	 */
	public function __construct(
		public readonly string $resource,
		public readonly string $action,
		public readonly array $body,
		public readonly ?array $existingObject,
	) {
	}//end __construct()

	/**
	 * Whether this is the given action on the given resource.
	 *
	 * @param string $resource The resource name.
	 * @param string $action   The action.
	 *
	 * @return bool True when both match.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function is(string $resource, string $action): bool {
		return $this->resource === $resource && $this->action === $action;
	}//end is()
}//end class
