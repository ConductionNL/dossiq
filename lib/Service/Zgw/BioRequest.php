<?php

/**
 * One BesluitInformatieObject request as the BRC link handler serves it.
 *
 * Built once per endpoint by `BrcDocumentLinkHandler` and handed through its private steps: the
 * incoming request, OpenRegister's object service and the resource's mapping configuration.
 *
 * @category Zgw
 * @package  OCA\Dossiq\Service\Zgw
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

namespace OCA\Dossiq\Service\Zgw;

use OCP\IRequest;

/**
 * One BesluitInformatieObject request as the BRC link handler serves it.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class BioRequest {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request       The incoming request.
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $mappingConfig The resource's mapping configuration.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IRequest $request,
		public readonly object $objectService,
		public readonly array $mappingConfig,
	) {
	}//end __construct()
}//end class
