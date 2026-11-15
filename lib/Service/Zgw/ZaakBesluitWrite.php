<?php

/**
 * One zaakbesluit record to write in the cases register (brc-006).
 *
 * Built by `BrcCrossRegisterSync` once the zaak and the register are known: OpenRegister's object
 * service, the zaken register, the zaakbesluit schema, the zaak and the besluit.
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

/**
 * One zaakbesluit record to write in the cases register (brc-006).
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZaakBesluitWrite {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The zaken register.
	 * @param string $zbSchema      The zaakbesluit schema.
	 * @param string $zaakUuid      The zaak UUID.
	 * @param string $decisionUrl   The besluit URL.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $zbSchema,
		public readonly string $zaakUuid,
		public readonly string $decisionUrl,
	) {
	}//end __construct()
}//end class
