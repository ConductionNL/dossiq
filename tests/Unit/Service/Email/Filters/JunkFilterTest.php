<?php

/**
 * Unit tests for the junk filter.
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
use OCA\Dossiq\Service\Email\Filters\JunkFilter;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\JunkRules;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Junk is quarantined, naming the rule that matched; the rest passes.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\JunkFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 * @uses \OCA\Dossiq\Service\Email\JunkRules
 */
class JunkFilterTest extends TestCase {

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
	 * A filter over the given administered rules.
	 *
	 * @param string $rules The configured rules, one per line.
	 *
	 * @return JunkFilter The filter.
	 */
	private function filter(string $rules): JunkFilter {
		return new JunkFilter(rules: new JunkRules(appConfig: $this->appConfig(values: [JunkRules::RULES_KEY => $rules])));
	}//end filter()

	/**
	 * The filter's name and place in the order.
	 *
	 * @return void
	 */
	public function testNameAndOrder(): void {
		$filter = $this->filter(rules: '');

		self::assertSame('junk', $filter->name());
		self::assertSame(60, $filter->order());
	}//end testNameAndOrder()

	/**
	 * The mail server's spam flag quarantines rather than rejects.
	 *
	 * @return void
	 */
	public function testTheSpamFlagQuarantines(): void {
		$verdict = $this->filter(rules: '')->decide(message: $this->message(headers: ['x-spam-flag' => ' YES ']));

		self::assertSame(FilterOutcome::QUARANTINE, $verdict->outcome);
		self::assertSame('junk', $verdict->filterName);
		self::assertSame(
			'Rule mail-server-marked-it-spam: The receiving mail server set X-Spam-Flag: YES on the message.',
			$verdict->reason
		);
	}//end testTheSpamFlagQuarantines()

	/**
	 * An administered subject rule quarantines and is named by its position.
	 *
	 * @return void
	 */
	public function testAnAdministeredRuleNamesItsPosition(): void {
		$verdict = $this->filter(rules: "x-campaign:\nGRATIS")->decide(message: $this->message(subject: 'Gratis cadeau'));

		self::assertSame(FilterOutcome::QUARANTINE, $verdict->outcome);
		self::assertSame('Rule administered-2: An administrator wrote: GRATIS', $verdict->reason);
	}//end testAnAdministeredRuleNamesItsPosition()

	/**
	 * A header rule with an empty needle matches the header's presence.
	 *
	 * @return void
	 */
	public function testAHeaderPresenceRuleQuarantines(): void {
		$filter = $this->filter(rules: 'x-campaign:');

		self::assertSame(
			'Rule administered-1: An administrator wrote: x-campaign:',
			$filter->decide(message: $this->message(headers: ['x-campaign' => '']))->reason
		);
		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message())->outcome);
	}//end testAHeaderPresenceRuleQuarantines()

	/**
	 * Ordinary mail and a spam flag of NO pass.
	 *
	 * @return void
	 */
	public function testCleanMailPasses(): void {
		$filter = $this->filter(rules: 'gratis');

		$verdict = $filter->decide(message: $this->message(headers: ['x-spam-flag' => 'NO']));

		self::assertSame(FilterOutcome::PASS, $verdict->outcome);
		self::assertSame('', $verdict->reason);
	}//end testCleanMailPasses()
}//end class
