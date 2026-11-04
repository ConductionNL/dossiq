<?php

/**
 * How a case mail goes out: the outbound mail path, the recipient allowlist and the opt-out.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * How a case mail goes out: the outbound mail path, the recipient allowlist and the opt-out.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class OutboundMailRules {
	/**
	 * Constructor.
	 *
	 * @param OutboundCaseMail   $outbound  The case's Mail account, which sends and files the message
	 * @param RecipientAllowlist $allowlist Outbound recipient policy
	 * @param CaseMailOptOut     $optOut    Asks integriq first and places its unsubscribe link
	 */
	public function __construct(
		public readonly OutboundCaseMail $outbound,
		public readonly RecipientAllowlist $allowlist,
		public readonly CaseMailOptOut $optOut,
	) {
	}//end __construct()
}//end class
