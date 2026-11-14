<?php

/**
 * Dossiq CallbackStore.
 *
 * Where callback requests live: OpenRegister's object service, the register
 * and the callback request schema.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * The object service, register and schema callback requests are stored in.
 *
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
 */
final class CallbackStore {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register id.
	 * @param string $schema        The callback request schema id.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
