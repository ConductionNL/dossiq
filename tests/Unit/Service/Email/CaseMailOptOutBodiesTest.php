<?php

/**
 * The unsubscribe link every non-exempt case mail carries, in its HTML and plain bodies and its headers.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use InvalidArgumentException;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\OptOutGate;
use OCA\OpenRegister\Notifications\Service\Notification\UnsubscribeHeaders;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Email\CaseMailOptOut
 */
class CaseMailOptOutBodiesTest extends TestCase {

	/**
	 * The opt-out over an echoing translator and the given header writer.
	 *
	 * @param UnsubscribeHeaders|null $headers The header writer.
	 *
	 * @return CaseMailOptOut
	 */
	private function optOut(?UnsubscribeHeaders $headers = null): CaseMailOptOut {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CaseMailOptOut(
			gate: new OptOutGate($this->createMock(IEventDispatcher::class), $this->createMock(IAppConfig::class), new NullLogger()),
			l10n: $l10n,
			unsubscribeHeaders: $headers,
		);
	}//end optOut()

	public function testOnlyTheThreeCaseMailCategoriesAreAccepted(): void {
		$optOut = $this->optOut();
		foreach (CaseMailOptOut::CASE_MAIL_CATEGORIES as $category) {
			$optOut->assertCaseMailCategory(category: $category);
		}

		self::assertSame(['case-update', 'besluit', 'statutory'], CaseMailOptOut::CASE_MAIL_CATEGORIES);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('invalid-category');
		$optOut->assertCaseMailCategory(category: 'newsletter');
	}//end testOnlyTheThreeCaseMailCategoriesAreAccepted()

	public function testBothBodiesCarryTheEscapedLink(): void {
		$bodies = $this->optOut()->bodies(body: '<p>Uw zaak is bijgewerkt.</p>', unsubscribe: ['url' => 'https://dossiq.example/u?t=a&b=1']);

		self::assertSame(
			"<p>Uw zaak is bijgewerkt.</p>\n<p>No longer want updates about this case? <a href=\"https://dossiq.example/u?t=a&amp;b=1\">Unsubscribe</a></p>",
			$bodies['html']
		);
		self::assertSame(
			"Uw zaak is bijgewerkt.\n\nNo longer want updates about this case? Unsubscribe: https://dossiq.example/u?t=a&b=1",
			$bodies['plain']
		);
	}//end testBothBodiesCarryTheEscapedLink()

	public function testAGivenPlainBodyIsUsedInsteadOfStrippedHtml(): void {
		$bodies = $this->optOut()->bodies(body: '<b>html</b>', unsubscribe: null, plain: 'eigen tekst');

		self::assertSame(['html' => '<b>html</b>', 'plain' => 'eigen tekst'], $bodies);
	}//end testAGivenPlainBodyIsUsedInsteadOfStrippedHtml()

	public function testAnUnusableUrlAddsNoLink(): void {
		$optOut = $this->optOut();

		foreach ([['url' => ''], ['url' => 'javascript:alert(1)'], ['url' => 'https://x.example/a b'], ['url' => 'https://x.example/"onmouseover'], []] as $unsubscribe) {
			self::assertSame(['html' => '<p>x</p>', 'plain' => 'x'], $optOut->bodies(body: '<p>x</p>', unsubscribe: $unsubscribe));
		}
	}//end testAnUnusableUrlAddsNoLink()

	public function testDressSetsBothBodiesAndTheHeaders(): void {
		$unsubscribe = ['url' => 'https://dossiq.example/u'];
		$headers = $this->createMock(UnsubscribeHeaders::class);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setHtmlBody')->with($this->stringContains('href="https://dossiq.example/u"'));
		$message->expects($this->once())->method('setPlainBody')->with($this->stringEndsWith('Unsubscribe: https://dossiq.example/u'));
		$headers->expects($this->once())->method('apply')->with($message, $unsubscribe)->willReturn(true);

		$this->optOut(headers: $headers)->dress(message: $message, body: 'Body', unsubscribe: $unsubscribe);
	}//end testDressSetsBothBodiesAndTheHeaders()

	public function testAnExemptMailIsDressedWithoutLinkOrHeaders(): void {
		$headers = $this->createMock(UnsubscribeHeaders::class);
		$headers->expects($this->never())->method('apply');
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setHtmlBody')->with('<i>Besluit</i>');
		$message->expects($this->once())->method('setPlainBody')->with('Besluit');

		$this->optOut(headers: $headers)->dress(message: $message, body: '<i>Besluit</i>', unsubscribe: null);
	}//end testAnExemptMailIsDressedWithoutLinkOrHeaders()
}//end class
