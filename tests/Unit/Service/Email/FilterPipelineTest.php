<?php

/**
 * The declared filter order, and the default that is written down.
 *
 * 🔴 THE EMPTY PIPELINE IS THE FAILURE THIS FILE EXISTS TO CATCH. Nextcloud
 * cannot autowire a constructor that takes a list of implementations, so a
 * pipeline assembled by the container rather than by
 * {@see \OCA\Dossiq\AppInfo\Registrar\MailIntakeRegistrar} would be EMPTY, and
 * an empty pipeline accepts every message and reports the same success as one
 * that ran every filter. `testTheAssembledPipelineRunsEveryShippedFilter` is
 * the one that would redden.
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

use OCA\Dossiq\Service\Email\Filters\AutoReplyFilter;
use OCA\Dossiq\Service\Email\Filters\BlockedSenderFilter;
use OCA\Dossiq\Service\Email\Filters\BounceNotificationFilter;
use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\Filters\InboundMailFilter;
use OCA\Dossiq\Service\Email\Filters\JunkFilter;
use OCA\Dossiq\Service\Email\Filters\OutOfOfficeFilter;
use OCA\Dossiq\Service\Email\Filters\OwnNotificationLoopFilter;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\JunkRules;
use OCA\Dossiq\Service\Email\SenderBlocklist;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the intake filter pipeline.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\FilterPipeline
 * @uses \OCA\Dossiq\Service\Email\Filters\AutoReplyFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\BlockedSenderFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\BounceNotificationFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\Filters\JunkFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\OutOfOfficeFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\OwnNotificationLoopFilter
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 * @uses \OCA\Dossiq\Service\Email\JunkRules
 * @uses \OCA\Dossiq\Service\Email\SenderBlocklist
 */
class FilterPipelineTest extends TestCase {

	/**
	 * Build the pipeline the app actually ships, filters and order included.
	 *
	 * @param array<string, string> $config The app config values.
	 *
	 * @return FilterPipeline The pipeline.
	 */
	private function shippedPipeline(array $config = []): FilterPipeline {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new FilterPipeline(
			logger: new NullLogger(),
			filters: [
				new JunkFilter(rules: new JunkRules(appConfig: $appConfig)),
				new BlockedSenderFilter(
					blocklist: new SenderBlocklist(appConfig: $appConfig, gateway: new FakeMailGateway())
				),
				new OutOfOfficeFilter(),
				new AutoReplyFilter(),
				new BounceNotificationFilter(),
				new OwnNotificationLoopFilter(appConfig: $appConfig),
			]
		);
	}//end shippedPipeline()

	/**
	 * Build a message from a raw source.
	 *
	 * @param string $source The raw source.
	 *
	 * @return InboundMessage The message.
	 */
	private function message(string $source): InboundMessage {
		$subject = '';
		if (preg_match('/^Subject:\s*(.*)$/mi', $source, $matches) === 1) {
			$subject = trim($matches[1]);
		}

		$from = '';
		if (preg_match('/^From:\s*(.*)$/mi', $source, $matches) === 1) {
			$from = trim($matches[1]);
		}

		return (new InboundMessage(
			accountId: 7,
			mailbox: 'INBOX',
			uid: 1,
			subject: $subject,
			from: $from,
		))->withSource(source: $source);
	}//end message()

	/**
	 * The shipped pipeline runs every filter, in the declared order.
	 *
	 * 🔴 The empty-pipeline guard. A pipeline the container assembled itself
	 * would be empty and would accept every message while reporting success.
	 *
	 * @return void
	 */
	public function testTheAssembledPipelineRunsEveryShippedFilter(): void {
		self::assertSame(
			[
				OwnNotificationLoopFilter::NAME,
				BounceNotificationFilter::NAME,
				AutoReplyFilter::NAME,
				OutOfOfficeFilter::NAME,
				BlockedSenderFilter::NAME,
				JunkFilter::NAME,
			],
			$this->shippedPipeline()->declaredOrder()
		);
	}//end testTheAssembledPipelineRunsEveryShippedFilter()

	/**
	 * An out-of-office auto-reply does not open a case, and the log names why.
	 *
	 * @return void
	 */
	public function testAnAutoReplyDoesNotOpenACase(): void {
		$verdict = $this->shippedPipeline()->run(
			message: $this->message(
				"From: ambtenaar@gemeente.nl\r\n"
				. "Subject: Re: [ZAAK-2026-000142] uw aanvraag\r\n"
				. "Auto-Submitted: auto-replied\r\n\r\nIk ben afwezig."
			)
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame(AutoReplyFilter::NAME, $verdict->filterName);
		self::assertNotSame('', $verdict->reason);
	}//end testAnAutoReplyDoesNotOpenACase()

	/**
	 * An absence notice that forgot to declare itself is still caught.
	 *
	 * @return void
	 */
	public function testAnUndeclaredOutOfOfficeIsCaughtOnItsSubject(): void {
		$verdict = $this->shippedPipeline()->run(
			message: $this->message(
				"From: ambtenaar@gemeente.nl\r\n"
				. "Subject: Automatische beantwoording: uw bericht\r\n\r\nIk ben er niet."
			)
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame(OutOfOfficeFilter::NAME, $verdict->filterName);
	}//end testAnUndeclaredOutOfOfficeIsCaughtOnItsSubject()

	/**
	 * A delivery failure notification does not open a case.
	 *
	 * @return void
	 */
	public function testABounceNotificationDoesNotOpenACase(): void {
		$verdict = $this->shippedPipeline()->run(
			message: $this->message(
				"From: MAILER-DAEMON@gemeente.nl\r\n"
				. "Subject: Undelivered Mail Returned to Sender\r\n"
				. "Content-Type: multipart/report; report-type=delivery-status\r\n\r\nfailed"
			)
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame(BounceNotificationFilter::NAME, $verdict->filterName);
	}//end testABounceNotificationDoesNotOpenACase()

	/**
	 * Our own notification coming back does not start a loop.
	 *
	 * @return void
	 */
	public function testOurOwnNotificationComingBackStartsNoLoop(): void {
		$verdict = $this->shippedPipeline(config: ['email_from_address' => 'zaken@gemeente.nl'])->run(
			message: $this->message(
				"From: Gemeente <zaken@gemeente.nl>\r\n"
				. "Subject: [ZAAK-2026-000142] statuswijziging\r\n\r\ntekst"
			)
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame(OwnNotificationLoopFilter::NAME, $verdict->filterName);
	}//end testOurOwnNotificationComingBackStartsNoLoop()

	/**
	 * A message nothing objects to is accepted by the written-down default.
	 *
	 * @return void
	 */
	public function testAMessageNothingObjectsToIsAccepted(): void {
		$verdict = $this->shippedPipeline()->run(
			message: $this->message(
				"From: aanvrager@voorbeeld.nl\r\n"
				. "Subject: Bezwaar tegen de kapvergunning\r\n\r\nGeachte gemeente,"
			)
		);

		self::assertSame(FilterOutcome::ACCEPT, $verdict->outcome);
		self::assertSame('', $verdict->filterName, 'no filter decided, the default did');
		self::assertSame(FilterOutcome::ACCEPT, FilterOutcome::DEFAULT_OUTCOME);
	}//end testAMessageNothingObjectsToIsAccepted()

	/**
	 * A blocked sender opens no case, and the block is named.
	 *
	 * @return void
	 */
	public function testABlockedSenderOpensNoCase(): void {
		$verdict = $this->shippedPipeline(
			config: ['email_intake_blocklist' => 'spammer@voorbeeld.nl']
		)->run(
			message: $this->message("From: Spammer <spammer@voorbeeld.nl>\r\nSubject: aanbod\r\n\r\ntekst")
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame(BlockedSenderFilter::NAME, $verdict->filterName);
	}//end testABlockedSenderOpensNoCase()

	/**
	 * A junk verdict quarantines rather than refuses, and names its rule.
	 *
	 * @return void
	 */
	public function testAJunkVerdictNamesTheRuleThatReachedIt(): void {
		$verdict = $this->shippedPipeline()->run(
			message: $this->message("From: a@b.nl\r\nSubject: win\r\nX-Spam-Flag: YES\r\n\r\ntekst")
		);

		self::assertSame(FilterOutcome::QUARANTINE, $verdict->outcome);
		self::assertSame(JunkFilter::NAME, $verdict->filterName);
		self::assertStringContainsString(JunkRules::SPAM_FLAG_RULE, $verdict->reason);
	}//end testAJunkVerdictNamesTheRuleThatReachedIt()

	/**
	 * A filter that throws decides nothing, and the rest of the pipeline runs.
	 *
	 * One broken spam heuristic must not stop intake for the whole mailbox:
	 * that turns a bug into a missed statutory term.
	 *
	 * @return void
	 */
	public function testAThrowingFilterDecidesNothingAndTheRestStillRuns(): void {
		$throwing = new class implements InboundMailFilter {
			/**
			 * The name.
			 *
			 * @return string The name.
			 */
			public function name(): string {
				return 'throwing';
			}

			/**
			 * First in the order, so the throw happens before anything else.
			 *
			 * @return integer The order.
			 */
			public function order(): int {
				return 1;
			}

			/**
			 * Always throws.
			 *
			 * @param InboundMessage $message The message.
			 *
			 * @return FilterVerdict Never returned.
			 */
			public function decide(InboundMessage $message): FilterVerdict {
				unset($message);
				throw new \RuntimeException('broken heuristic');
			}
		};

		$pipeline = new FilterPipeline(
			logger: new NullLogger(),
			filters: [$throwing, new AutoReplyFilter()]
		);

		$verdict = $pipeline->run(
			message: $this->message("From: a@b.nl\r\nSubject: hallo\r\nPrecedence: bulk\r\n\r\ntekst")
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame(AutoReplyFilter::NAME, $verdict->filterName);
	}//end testAThrowingFilterDecidesNothingAndTheRestStillRuns()
}//end class
