<?php

/**
 * The check that stops a forged reply reaching somebody else's case.
 *
 * 🔴 THIS IS THE MEASURED FAILURE. `InboundEmailJob` matched a bracketed case
 * tag in the subject and checked nothing else, so anyone who knew a case number
 * could file a bezwaar on it. Frappe's version of the same gap was measured
 * with a forged `In-Reply-To` landing one customer's mail on another's case.
 *
 * The fake gateway holds a set of message ids the account really sent, so the
 * interesting case is a reference to an id that is not in it. A mock told to
 * return `false` would prove only that `false` was configured.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\ThreadingCheck;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the threading claim check.
 *
 * @covers \OCA\Dossiq\Service\Email\ThreadingCheck
 */
class ThreadingCheckTest extends TestCase {

	/**
	 * The account, holding one message it really sent.
	 *
	 * @var FakeMailGateway
	 */
	private FakeMailGateway $gateway;

	/**
	 * The check under test.
	 *
	 * @var ThreadingCheck
	 */
	private ThreadingCheck $threading;

	/**
	 * Set up an account that sent exactly one message.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = new FakeMailGateway();
		$this->gateway->heldMessageIds = ['ours-2026-000142@gemeente.nl'];
		$this->threading = new ThreadingCheck(gateway: $this->gateway);
	}//end setUp()

	/**
	 * Build a message from a raw source.
	 *
	 * @param string $source The raw source.
	 *
	 * @return InboundMessage The message.
	 */
	private function message(string $source): InboundMessage {
		return (new InboundMessage(accountId: 7, mailbox: 'INBOX', uid: 1))->withSource(source: $source);
	}//end message()

	/**
	 * A forged reference is `fail`, and a fail stops a subject-tag link.
	 *
	 * @return void
	 */
	public function testAForgedReferenceDoesNotReachSomebodyElsesCase(): void {
		$message = $this->message(
			"From: vervalser@voorbeeld.nl\r\n"
			. "Subject: Re: [ZAAK-2026-000142] mijn bezwaar\r\n"
			. "In-Reply-To: <nooit-verstuurd@voorbeeld.nl>\r\n\r\ntekst"
		);

		$result = $this->threading->resultFor(message: $message);

		self::assertSame(AuthenticationResult::FAIL, $result);
		self::assertFalse($this->threading->allowsSubjectTagLink(threadingResult: $result));
	}//end testAForgedReferenceDoesNotReachSomebodyElsesCase()

	/**
	 * A genuine reply is `pass`, and its subject tag is honoured.
	 *
	 * @return void
	 */
	public function testAGenuineReplyReachesItsCase(): void {
		$message = $this->message(
			"From: aanvrager@voorbeeld.nl\r\n"
			. "Subject: Re: [ZAAK-2026-000142] uw aanvraag\r\n"
			. "In-Reply-To: <ours-2026-000142@gemeente.nl>\r\n\r\ntekst"
		);

		$result = $this->threading->resultFor(message: $message);

		self::assertSame(AuthenticationResult::PASS, $result);
		self::assertTrue($this->threading->allowsSubjectTagLink(threadingResult: $result));
	}//end testAGenuineReplyReachesItsCase()

	/**
	 * A first message makes no claim, and `none` is not a failure.
	 *
	 * This is how a citizen quoting their own case number still reaches their
	 * case. Treating `none` as a failure would break the ordinary path.
	 *
	 * @return void
	 */
	public function testAFirstMessageMakesNoThreadingClaim(): void {
		$message = $this->message(
			"From: aanvrager@voorbeeld.nl\r\nSubject: [ZAAK-2026-000142] aanvulling\r\n\r\ntekst"
		);

		$result = $this->threading->resultFor(message: $message);

		self::assertSame(AuthenticationResult::NONE, $result);
		self::assertTrue($this->threading->allowsSubjectTagLink(threadingResult: $result));
	}//end testAFirstMessageMakesNoThreadingClaim()

	/**
	 * A `References` chain counts, not just `In-Reply-To`.
	 *
	 * Mail clients drop `In-Reply-To` and keep `References` often enough that
	 * reading only the first header would call genuine replies forged.
	 *
	 * @return void
	 */
	public function testAReferencesChainCountsAsAClaim(): void {
		$message = $this->message(
			"From: aanvrager@voorbeeld.nl\r\n"
			. "Subject: Re: uw aanvraag\r\n"
			. "References: <iets@elders.nl> <ours-2026-000142@gemeente.nl>\r\n\r\ntekst"
		);

		self::assertSame(AuthenticationResult::PASS, $this->threading->resultFor(message: $message));
	}//end testAReferencesChainCountsAsAClaim()

	/**
	 * With no mail app there is nothing to check against, so `unavailable`.
	 *
	 * And `unavailable` still allows the subject-tag link, because refusing
	 * every tagged mail on an instance without the Mail app would drop the
	 * ordinary path along with the forged one.
	 *
	 * @return void
	 */
	public function testAClaimNobodyCanCheckIsUnavailable(): void {
		$this->gateway->available = false;

		$message = $this->message(
			"From: a@b.nl\r\nSubject: Re: iets\r\nIn-Reply-To: <x@y.nl>\r\n\r\ntekst"
		);

		$result = $this->threading->resultFor(message: $message);

		self::assertSame(AuthenticationResult::UNAVAILABLE, $result);
		self::assertNotSame(AuthenticationResult::PASS, $result);
		self::assertTrue($this->threading->allowsSubjectTagLink(threadingResult: $result));
	}//end testAClaimNobodyCanCheckIsUnavailable()
}//end class
