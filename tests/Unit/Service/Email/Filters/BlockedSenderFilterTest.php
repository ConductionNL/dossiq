<?php

/**
 * Unit tests for the blocked-sender filter.
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

use OCA\Dossiq\Service\Email\Filters\BlockedSenderFilter;
use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\SenderBlocklist;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Senders an administrator blocked are refused; everyone else passes.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\BlockedSenderFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 * @uses \OCA\Dossiq\Service\Email\SenderBlocklist
 */
class BlockedSenderFilterTest extends TestCase {

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
	 * A filter over a blocklist with the given raw entries.
	 *
	 * @param string $entries The configured blocklist.
	 *
	 * @return BlockedSenderFilter The filter.
	 */
	private function filter(string $entries): BlockedSenderFilter {
		return new BlockedSenderFilter(
			blocklist: new SenderBlocklist(
				appConfig: $this->appConfig(values: [SenderBlocklist::BLOCKLIST_KEY => $entries]),
				gateway: new FakeMailGateway()
			)
		);
	}//end filter()

	/**
	 * The filter's name and place in the order.
	 *
	 * @return void
	 */
	public function testNameAndOrder(): void {
		$filter = $this->filter(entries: '');

		self::assertSame('blocked-sender', $filter->name());
		self::assertSame(50, $filter->order());
	}//end testNameAndOrder()

	/**
	 * A blocked address is refused, and the reason names it.
	 *
	 * @return void
	 */
	public function testABlockedAddressIsRejected(): void {
		$verdict = $this->filter(entries: 'Spam@Example.org, other@x.org')->decide(
			message: $this->message(from: 'Spammer <SPAM@example.org>')
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('blocked-sender', $verdict->filterName);
		self::assertSame('An administrator blocked spam@example.org from opening cases by mail.', $verdict->reason);
	}//end testABlockedAddressIsRejected()

	/**
	 * A blocked domain refuses every address in it, and only in it.
	 *
	 * @return void
	 */
	public function testABlockedDomainRefusesItsAddresses(): void {
		$filter = $this->filter(entries: '@junk.example');

		self::assertSame(FilterOutcome::REJECT, $filter->decide(message: $this->message(from: 'a@junk.example'))->outcome);
		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message(from: 'a@sub.junk.example'))->outcome);
	}//end testABlockedDomainRefusesItsAddresses()

	/**
	 * An unlisted sender passes.
	 *
	 * @return void
	 */
	public function testAnUnlistedSenderPasses(): void {
		$verdict = $this->filter(entries: 'spam@example.org')->decide(message: $this->message());

		self::assertSame(FilterOutcome::PASS, $verdict->outcome);
		self::assertSame('', $verdict->reason);
	}//end testAnUnlistedSenderPasses()

	/**
	 * A message without a sender is not looked up and passes.
	 *
	 * @return void
	 */
	public function testAnEmptySenderIsNotLookedUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::never())->method('getValueString');
		$filter = new BlockedSenderFilter(blocklist: new SenderBlocklist(appConfig: $appConfig, gateway: new FakeMailGateway()));

		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message(from: ''))->outcome);
	}//end testAnEmptySenderIsNotLookedUp()
}//end class
