<?php

/**
 * Dossiq MakesRealTermNoticeSender.
 *
 * The real e-mail transport for a requester notice, over a recording mailer,
 * integriq's opt-out list on an in-memory dispatcher and an in-memory ledger.
 * A test that asks whether a notice went out gets the real refusals (no
 * address, opted out, no sender address, a mailer that throws) instead of a
 * mock that answers "sent" for anything.
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Builds the real TermNoticeSender for a test.
 */
trait MakesRealTermNoticeSender {

	/**
	 * Every message the recording mailer was handed.
	 *
	 * @var list<IMessage>
	 */
	protected array $mailed = [];

	/**
	 * The real sender.
	 *
	 * @param bool   $mailerThrows Whether the mail server refuses every message.
	 * @param string $fromAddress  The configured sender address.
	 *
	 * @return TermNoticeSender The sender.
	 */
	protected function realTermNoticeSender(bool $mailerThrows = false, string $fromAddress = 'zaken@gemeente.nl'): TermNoticeSender {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(static fn (): RecordingMessage => new RecordingMessage());
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message) use ($mailerThrows): array {
				if ($mailerThrows === true) {
					throw new RuntimeException('Connection could not be established with host smtp.gemeente.nl');
				}

				$this->mailed[] = $message;
				return [];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'email_from_address' => $fromAddress,
				'email_from_name' => 'Gemeente',
				default => $default,
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		$dispatcher = new InMemoryEventDispatcher();
		FakeIntegriqOptOuts::on($dispatcher);
		$gateConfig = $this->createMock(IAppConfig::class);
		$gateConfig->method('getValueString')->willReturnArgument(2);

		return new TermNoticeSender(
			mailer: $mailer,
			appConfig: $appConfig,
			optOut: new CaseMailOptOut(new OptOutGate($dispatcher, $gateConfig, new NullLogger()), $l10n, new UnsubscribeHeaders(new NullLogger())),
			ledger: new InMemoryTermNoticeLedger(),
			logger: new NullLogger(),
		);
	}//end realTermNoticeSender()
}//end trait
