<?php

/**
 * Dossiq MakesOutboundCaseMail.
 *
 * The real outbound mail for a test: the real MailTransportPolicy and
 * SenderIdentity over a fake mail app, so a notice or a case mail leaves
 * through whichever transport the test's app config names, exactly as it does
 * in production.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Support
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

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\MailTransportPolicy;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Email\SenderIdentity;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\NullLogger;

/**
 * Builds a real OutboundCaseMail for a TestCase.
 */
trait MakesOutboundCaseMail {

	/**
	 * The real outbound mail over the given mailer, config and mail app.
	 *
	 * @param IMailer                   $mailer    Nextcloud's mailer.
	 * @param IAppConfig                $appConfig Holds the sender address and `mail_transport_by_kind`.
	 * @param CaseMailOptOut            $optOut    Places the unsubscribe link and headers.
	 * @param MailGatewayInterface|null $gateway   The mail app, a FakeMailGateway by default.
	 *
	 * @return OutboundCaseMail The outbound mail.
	 */
	protected function outboundCaseMail(
		IMailer $mailer,
		IAppConfig $appConfig,
		CaseMailOptOut $optOut,
		?MailGatewayInterface $gateway = null,
	): OutboundCaseMail {
		$gateway = ($gateway ?? new FakeMailGateway());
		$store   = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturn('');

		return new OutboundCaseMail(
			$gateway,
			new SenderIdentity($gateway, new IntakeAccount($appConfig), $this->createMock(CaseTypeResolver::class), $store),
			new MailTransportPolicy($appConfig),
			$optOut,
			$mailer,
			$appConfig,
			new NullLogger()
		);
	}//end outboundCaseMail()
}//end trait
