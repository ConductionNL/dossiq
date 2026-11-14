<?php

/**
 * Where the inbound mail poll reads from and hands to: the mail gateway, the intake account and the intake.
 *
 * @category Service
 * @package  OCA\Dossiq\BackgroundJob
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

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Kernel\Contracts\InboundMailIntakeInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeAccountInterface;
use OCA\Dossiq\Service\Email\MailGatewayInterface;

/**
 * Where the inbound mail poll reads from and hands to: the mail gateway, the intake account and the intake.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class MailIntakeSource {
	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway The mail gateway.
	 * @param IntakeAccountInterface        $account The account and folder intake reads.
	 * @param InboundMailIntakeInterface    $intake  The intake path.
	 */
	public function __construct(
		public readonly MailGatewayInterface $gateway,
		public readonly IntakeAccountInterface $account,
		public readonly InboundMailIntakeInterface $intake,
	) {
	}//end __construct()
}//end class
