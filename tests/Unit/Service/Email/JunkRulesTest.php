<?php

/**
 * Which junk rule, if any, an inbound message falls under.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\JunkRules;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\JunkRules
 * @uses   \OCA\Dossiq\Service\Email\InboundMessage
 */
class JunkRulesTest extends TestCase {

	/**
	 * Rules over the given administered text.
	 *
	 * @param string $administered The configured rules, one per line.
	 *
	 * @return JunkRules
	 */
	private function rules(string $administered): JunkRules {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->with(Application::APP_ID, JunkRules::RULES_KEY, '')
			->willReturn($administered);

		return new JunkRules(appConfig: $config);
	}//end rules()

	/**
	 * A message with the given subject and headers.
	 *
	 * @param string                $subject The subject.
	 * @param array<string, string> $headers Headers, lower-cased names.
	 *
	 * @return InboundMessage
	 */
	private function message(string $subject, array $headers = []): InboundMessage {
		return new InboundMessage(accountId: 1, mailbox: 'INBOX', uid: 7, subject: $subject, headers: $headers);
	}//end message()

	public function testTheSpamFlagRuleAlwaysComesFirstThenTheAdministeredLinesNumbered(): void {
		$rules = $this->rules(administered: "  Nieuwsbrief  \n\n\r\nList-Unsubscribe:\n")->rules();

		self::assertSame(
			[
				['name' => JunkRules::SPAM_FLAG_RULE, 'description' => 'The receiving mail server set X-Spam-Flag: YES on the message.'],
				['name' => 'administered-1', 'description' => 'An administrator wrote: Nieuwsbrief'],
				['name' => 'administered-2', 'description' => 'An administrator wrote: List-Unsubscribe:'],
			],
			$rules
		);
	}//end testTheSpamFlagRuleAlwaysComesFirstThenTheAdministeredLinesNumbered()

	public function testAServerSpamFlagMatchesBeforeAnyAdministeredRule(): void {
		$match = $this->rules(administered: 'Factuur')->matching(message: $this->message(subject: 'Factuur', headers: ['x-spam-flag' => ' Yes ']));

		self::assertSame(JunkRules::SPAM_FLAG_RULE, $match['name']);
	}//end testAServerSpamFlagMatchesBeforeAnyAdministeredRule()

	public function testASpamFlagOfNoIsNotJunk(): void {
		self::assertNull($this->rules(administered: '')->matching(message: $this->message(subject: 'Hallo', headers: ['x-spam-flag' => 'NO'])));
	}//end testASpamFlagOfNoIsNotJunk()

	public function testABareLineMatchesTheSubjectCaseInsensitively(): void {
		$match = $this->rules(administered: "loterij\nnieuwsbrief")->matching(message: $this->message(subject: 'Uw NIEUWSBRIEF van mei'));

		self::assertSame(['name' => 'administered-2', 'description' => 'An administrator wrote: nieuwsbrief'], $match);
	}//end testABareLineMatchesTheSubjectCaseInsensitively()

	public function testAHeaderLineMatchesTheHeaderValueNotTheSubject(): void {
		$rules = $this->rules(administered: 'X-Mailer: MassMail');

		self::assertSame('administered-1', $rules->matching(message: $this->message(subject: 'x', headers: ['x-mailer' => 'massmail 3.1']))['name']);
		self::assertNull($rules->matching(message: $this->message(subject: 'X-Mailer: MassMail')));
	}//end testAHeaderLineMatchesTheHeaderValueNotTheSubject()

	public function testAHeaderLineWithoutAValueMatchesOnPresenceAlone(): void {
		$rules = $this->rules(administered: 'List-Unsubscribe:');

		self::assertSame('administered-1', $rules->matching(message: $this->message(subject: 'x', headers: ['list-unsubscribe' => '']))['name']);
		self::assertNull($rules->matching(message: $this->message(subject: 'x', headers: ['list-id' => 'a'])));
	}//end testAHeaderLineWithoutAValueMatchesOnPresenceAlone()

	public function testALineStartingWithAColonIsASubjectRule(): void {
		$rules = $this->rules(administered: ':)');

		self::assertSame('administered-1', $rules->matching(message: $this->message(subject: 'Groetjes :)'))['name']);
		self::assertNull($rules->matching(message: $this->message(subject: 'Groetjes')));
	}//end testALineStartingWithAColonIsASubjectRule()
}//end class
