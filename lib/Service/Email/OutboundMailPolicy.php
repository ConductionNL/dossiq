<?php

/**
 * Who a case mail is sent as and on what terms: the sender identity, the transport policy and the opt-out.
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * Who a case mail is sent as and on what terms: the sender identity, the transport policy and the opt-out.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */
final class OutboundMailPolicy {
	/**
	 * Constructor.
	 *
	 * @param SenderIdentity      $identity Which account a case's mail leaves from.
	 * @param MailTransportPolicy $policy   Which transport each kind leaves through.
	 * @param CaseMailOptOut      $optOut   Places the unsubscribe link and headers.
	 */
	public function __construct(
		public readonly SenderIdentity $identity,
		public readonly MailTransportPolicy $policy,
		public readonly CaseMailOptOut $optOut,
	) {
	}//end __construct()
}//end class
