<?php

/**
 * Dossiq ThreadingLookup.
 *
 * The Mail account and Mail's manager one call on that account asks: a
 * threading lookup, a source read, a move or an attachment list.
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
 * @spec openspec/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * The account and the manager one call asks, built by MailServices for NextcloudMailGateway.
 *
 * @spec openspec/specs/inbound-mail-filters/spec.md
 */
final class ThreadingLookup {

	/**
	 * Constructor.
	 *
	 * @param object $account Mail's account entity.
	 * @param object $manager Mail's manager, which offers `getByMessageId`.
	 */
	public function __construct(
		public readonly object $account,
		public readonly object $manager,
	) {
	}//end __construct()
}//end class
