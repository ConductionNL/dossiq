<?php

/**
 * Where an inbound mail is filed: on its case, as unmatched mail, and in the archive.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\EmailArchivalService;

/**
 * Where an inbound mail is filed: on its case, as unmatched mail, and in the archive.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class InboundMailFiling {
	/**
	 * Constructor.
	 *
	 * @param CaseEmailRepository  $cases     Resolves a case identifier to its id.
	 * @param UnmatchedMailIntake  $unmatched What a message nobody claims becomes.
	 * @param EmailArchivalService $archival  Files the message on its case.
	 */
	public function __construct(
		public readonly CaseEmailRepository $cases,
		public readonly UnmatchedMailIntake $unmatched,
		public readonly EmailArchivalService $archival,
	) {
	}//end __construct()
}//end class
