<?php

/**
 * One requested change to a ZTC resource: which resource, what action, and its body.
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
 * A change a client asks of a catalogi resource.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZtcChange {
	/**
	 * Constructor.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $action   The action: create, update, patch or destroy.
	 * @param array  $body     The request body.
	 */
	public function __construct(
		public readonly string $resource,
		public readonly string $action,
		public readonly array $body,
	) {
	}//end __construct()
}//end class
