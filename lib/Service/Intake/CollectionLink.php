<?php

/**
 * Dossiq CollectionLink.
 *
 * One collection being linked to the case it started: OpenRegister's object
 * service, the dossiq register, the case and the collection as read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * A collection and the case it is linked to.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */
final class CollectionLink {

	/**
	 * Constructor.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseId        The case.
	 * @param array<string, mixed> $collection    The collection as owned() read it.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseId,
		public readonly array $collection,
	) {
	}//end __construct()
}//end class
