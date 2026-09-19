<?php

/**
 * The four authentication results, and the one rule that matters about them.
 *
 * 🔴 `unavailable` IS NOT `pass`. Every assertion here that asserts a value is
 * `unavailable` also asserts, separately, that it is NOT `pass`. That looks
 * redundant and is not: the whole class of bug this guards against is a
 * refactor that folds "we did not look" into the success value, and an
 * assertion on the exact value would go green if both constants ever pointed
 * at the same string.
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
use OCA\Dossiq\Service\Email\AuthenticationVerdict;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\ThreadingCheck;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sender-authentication verdict.
 *
 * @covers \OCA\Dossiq\Service\Email\AuthenticationVerdict
 * @uses \OCA\Dossiq\Service\Email\AuthenticationResult
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 * @uses \OCA\Dossiq\Service\Email\ThreadingCheck
 */
class AuthenticationVerdictTest extends TestCase {

	/**
	 * The gateway the verdict reads DKIM through.
	 *
	 * @var FakeMailGateway
	 */
	private FakeMailGateway $gateway;

	/**
	 * The verdict under test.
	 *
	 * @var AuthenticationVerdict
	 */
	private AuthenticationVerdict $verdict;

	/**
	 * Set up a verdict over a fake account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = new FakeMailGateway();
		$this->verdict = new AuthenticationVerdict(
			gateway: $this->gateway,
			threading: new ThreadingCheck(gateway: $this->gateway)
		);
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
	 * A message whose headers carry three passes reports three passes.
	 *
	 * @return void
	 */
	public function testASignedMessagePasses(): void {
		$this->gateway->dkim = AuthenticationResult::PASS;

		$results = $this->verdict->forMessage(
			message: $this->message(
				"Authentication-Results: mx.gemeente.nl; spf=pass smtp.mailfrom=voorbeeld.nl;"
				. " dkim=pass header.d=voorbeeld.nl; dmarc=pass header.from=voorbeeld.nl\r\n"
				. "DKIM-Signature: v=1; d=voorbeeld.nl\r\n"
				. "From: aanvrager@voorbeeld.nl\r\n\r\ntekst"
			)
		);

		self::assertSame(AuthenticationResult::PASS, $results['spf']);
		self::assertSame(AuthenticationResult::PASS, $results['dkim']);
		self::assertSame(AuthenticationResult::PASS, $results['dmarc']);
		self::assertFalse($this->verdict->hasFailure(results: $results));
	}//end testASignedMessagePasses()

	/**
	 * A message with no authentication header is `unavailable`, not `pass`.
	 *
	 * @return void
	 */
	public function testAnUnsignedMessageIsNotReportedAsAuthenticated(): void {
		$results = $this->verdict->forMessage(
			message: $this->message("From: aanvrager@voorbeeld.nl\r\nSubject: bezwaar\r\n\r\ntekst")
		);

		self::assertSame(AuthenticationResult::UNAVAILABLE, $results['spf']);
		self::assertSame(AuthenticationResult::UNAVAILABLE, $results['dmarc']);
		self::assertNotSame(AuthenticationResult::PASS, $results['spf']);
		self::assertNotSame(AuthenticationResult::PASS, $results['dmarc']);
	}//end testAnUnsignedMessageIsNotReportedAsAuthenticated()

	/**
	 * A header that exists and says nothing about SPF is still `unavailable`.
	 *
	 * The absence of a method inside a present header is as much "nobody
	 * checked" as the absence of the header.
	 *
	 * @return void
	 */
	public function testAHeaderSilentAboutAMethodIsUnavailable(): void {
		$results = $this->verdict->forMessage(
			message: $this->message(
				"Authentication-Results: mx.gemeente.nl; dkim=pass header.d=voorbeeld.nl\r\n"
				. "From: aanvrager@voorbeeld.nl\r\n\r\ntekst"
			)
		);

		self::assertSame(AuthenticationResult::UNAVAILABLE, $results['spf']);
		self::assertNotSame(AuthenticationResult::PASS, $results['spf']);
	}//end testAHeaderSilentAboutAMethodIsUnavailable()

	/**
	 * A failing DMARC is a failure the policy can act on.
	 *
	 * @return void
	 */
	public function testAFailingDmarcIsAFailure(): void {
		$results = $this->verdict->forMessage(
			message: $this->message(
				"Authentication-Results: mx.gemeente.nl; spf=fail; dmarc=fail header.from=voorbeeld.nl\r\n"
				. "From: vervalser@voorbeeld.nl\r\n\r\ntekst"
			)
		);

		self::assertSame(AuthenticationResult::FAIL, $results['dmarc']);
		self::assertTrue($this->verdict->hasFailure(results: $results));
	}//end testAFailingDmarcIsAFailure()

	/**
	 * The topmost header is read, because it is the receiving server's.
	 *
	 * A forger can add an `Authentication-Results` line of their own; only the
	 * one the accepting server wrote is above it.
	 *
	 * @return void
	 */
	public function testTheReceivingServersHeaderWinsOverAnUpstreamOne(): void {
		$results = $this->verdict->forMessage(
			message: $this->message(
				"Authentication-Results: mx.gemeente.nl; spf=fail; dmarc=fail\r\n"
				. "Authentication-Results: relay.voorbeeld.nl; spf=pass; dmarc=pass\r\n"
				. "From: vervalser@voorbeeld.nl\r\n\r\ntekst"
			)
		);

		self::assertSame(AuthenticationResult::FAIL, $results['spf']);
		self::assertSame(AuthenticationResult::FAIL, $results['dmarc']);
	}//end testTheReceivingServersHeaderWinsOverAnUpstreamOne()

	/**
	 * A `none` and an `unavailable` are absences, not failures.
	 *
	 * Most of the Dutch internet publishes no DMARC policy. Treating that as a
	 * failure would quarantine every message from a small gemeente.
	 *
	 * @return void
	 */
	public function testAnAbsenceOfEvidenceIsNotAFailure(): void {
		self::assertFalse(AuthenticationResult::isFailure(AuthenticationResult::NONE));
		self::assertFalse(AuthenticationResult::isFailure(AuthenticationResult::UNAVAILABLE));
		self::assertTrue(AuthenticationResult::isFailure(AuthenticationResult::FAIL));
	}//end testAnAbsenceOfEvidenceIsNotAFailure()

	/**
	 * A softfail is a fail, because the domain disowned the message.
	 *
	 * @return void
	 */
	public function testASoftfailIsAFailAndNotAnUnknown(): void {
		self::assertSame(AuthenticationResult::FAIL, AuthenticationResult::fromToken('softfail'));
		self::assertSame(AuthenticationResult::UNAVAILABLE, AuthenticationResult::fromToken('temperror'));
		self::assertNotSame(AuthenticationResult::PASS, AuthenticationResult::fromToken('neutral'));
	}//end testASoftfailIsAFailAndNotAnUnknown()

	/**
	 * The published unknown verdict is four times `unavailable`.
	 *
	 * Callers that cannot read a message use this rather than assembling their
	 * own, because the one thing none of them must do is invent a `pass`.
	 *
	 * @return void
	 */
	public function testTheUnknownVerdictInventsNoPass(): void {
		foreach (AuthenticationVerdict::unknown() as $result) {
			self::assertSame(AuthenticationResult::UNAVAILABLE, $result);
			self::assertNotSame(AuthenticationResult::PASS, $result);
		}
	}//end testTheUnknownVerdictInventsNoPass()
}//end class
