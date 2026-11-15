<?php

/**
 * Unit tests for the own-notification loop filter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email\Filters
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

namespace OCA\Dossiq\Tests\Unit\Service\Email\Filters;

use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\OwnNotificationLoopFilter;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Mail dossiq sent itself is refused before it can open a case.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\OwnNotificationLoopFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class OwnNotificationLoopFilterTest extends TestCase {

	/**
	 * An app config answering from a fixed map.
	 *
	 * @param array<string, string> $values Key to value.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(array $values): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				return ($values[$key] ?? $default);
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build a message with the given headers.
	 *
	 * @param array<string, string> $headers Lower-cased header names to values.
	 * @param string                $from    The From value.
	 * @param string                $subject The subject.
	 *
	 * @return InboundMessage The message.
	 */
	private function message(array $headers = [], string $from = 'Jan <jan@example.org>', string $subject = 'Vraag over mijn aanvraag'): InboundMessage {
		return new InboundMessage(
			accountId: 1,
			mailbox: 'INBOX',
			uid: 1,
			subject: $subject,
			from: $from,
			headers: $headers,
		);
	}//end message()

	/**
	 * The filter's name and place in the order: first.
	 *
	 * @return void
	 */
	public function testNameAndOrder(): void {
		$filter = new OwnNotificationLoopFilter(appConfig: $this->appConfig(values: []));

		self::assertSame('own-notification-loop', $filter->name());
		self::assertSame(10, $filter->order());
	}//end testNameAndOrder()

	/**
	 * The notification header is refused without reading the config.
	 *
	 * @return void
	 */
	public function testTheNotificationHeaderIsRejected(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::never())->method('getValueString');

		$verdict = (new OwnNotificationLoopFilter(appConfig: $appConfig))->decide(
			message: $this->message(headers: ['x-dossiq-notification' => '1'])
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('The message carries X-Dossiq-Notification, so dossiq sent it.', $verdict->reason);
	}//end testTheNotificationHeaderIsRejected()

	/**
	 * The instance's own from-address is read from dossiq's config and refused.
	 *
	 * @return void
	 */
	public function testTheOwnFromAddressIsRejected(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())
			->method('getValueString')
			->with('dossiq', 'email_from_address', '')
			->willReturn(' Zaken@Gemeente.example ');

		$verdict = (new OwnNotificationLoopFilter(appConfig: $appConfig))->decide(
			message: $this->message(from: 'Gemeente <ZAKEN@gemeente.example>')
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('The sender is this instance\'s own from-address, so the message came back to us.', $verdict->reason);
	}//end testTheOwnFromAddressIsRejected()

	/**
	 * Another sender passes when a from-address is configured.
	 *
	 * @return void
	 */
	public function testAnotherSenderPasses(): void {
		$filter = new OwnNotificationLoopFilter(appConfig: $this->appConfig(values: ['email_from_address' => 'zaken@gemeente.example']));

		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message())->outcome);
	}//end testAnotherSenderPasses()

	/**
	 * With no from-address configured, an empty sender is not mistaken for ours.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredAddressNeverMatches(): void {
		$filter = new OwnNotificationLoopFilter(appConfig: $this->appConfig(values: []));

		$verdict = $filter->decide(message: $this->message(from: ''));

		self::assertSame(FilterOutcome::PASS, $verdict->outcome);
		self::assertSame('own-notification-loop', $verdict->filterName);
	}//end testAnUnconfiguredAddressNeverMatches()
}//end class
