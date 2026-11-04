<?php

/**
 * Where an acknowledgement is delivered and recorded: the case contacts, the portal and the case fields.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\Email\CaseContactDirectory;

/**
 * Where an acknowledgement is delivered and recorded: the case contacts, the portal and the case fields.
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */
final class AcknowledgementDelivery {
	/**
	 * Constructor.
	 *
	 * @param CaseContactDirectory       $contacts The addresses registered on a case.
	 * @param PortalContributionProvider $portal   The one list of case fields a citizen may see.
	 * @param CaseFieldWriter            $writer   Partial writes to the stored case.
	 */
	public function __construct(
		public readonly CaseContactDirectory $contacts,
		public readonly PortalContributionProvider $portal,
		public readonly CaseFieldWriter $writer,
	) {
	}//end __construct()
}//end class
