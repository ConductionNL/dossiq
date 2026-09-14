<?php

/**
 * Unit tests for the intake log endpoints.
 *
 * 🔴 THE REFUSAL IS THE FIRST TEST, BECAUSE THE LOG HOLDS THE ORIGINALS. Every
 * entry carries the raw source of a message somebody sent a gemeente, which is
 * personal data about a person who never agreed to the whole instance reading
 * it. So each endpoint asks `IntakePolicy::mayRunIntake()` BEFORE it reads
 * anything, and {@see self::testEveryEndpointRefusesAReaderWithoutTheIntakeRole}
 * sweeps them rather than asserting one: an endpoint added without the guard is
 * exactly the shape this catches.
 *
 * THE CORRECTION PATH IS ASSERTED ON BOTH HALVES. Marking a message as not junk
 * has to send it back through the pipeline AND record the correction; a version
 * that only amends the entry leaves a message classified wrongly and looks like
 * it worked, because the row changes either way.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTime;
use OCA\Dossiq\Controller\MailIntakeController;
use OCA\Dossiq\Service\Email\BounceAction;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Email\JunkRules;
use OCA\Dossiq\Service\Email\MoveAction;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the role guard, the log read, the release and the junk correction.
 *
 * @covers \OCA\Dossiq\Controller\MailIntakeController
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class MailIntakeControllerTest extends TestCase {

	/**
	 * The log.
	 *
	 * @var IntakeLog&MockObject
	 */
	private IntakeLog $log;

	/**
	 * Who may run intake.
	 *
	 * @var IntakePolicy&MockObject
	 */
	private IntakePolicy $policy;

	/**
	 * The intake path, for a released or corrected message.
	 *
	 * @var InboundMailIntake&MockObject
	 */
	private InboundMailIntake $intake;

	/**
	 * Whatever the last amend wrote.
	 *
	 * @var array<string, mixed>
	 */
	private array $amended = [];

	/**
	 * Build collaborators for a caller who holds the intake role.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->amended = [];

		$this->log = $this->createMock(IntakeLog::class);
		$this->log->method('search')->willReturn([['sender' => 'aanvrager@voorbeeld.nl']]);
		$this->log->method('amend')->willReturnCallback(
			function (string $entryId, array $changes): bool {
				$this->amended = $changes;

				return true;
			}
		);

		$this->policy = $this->createMock(IntakePolicy::class);
		$this->policy->method('mayRunIntake')->willReturn(true);

		$this->intake = $this->createMock(InboundMailIntake::class);
		$this->intake->method('process')->willReturn(IntakeLog::OUTCOME_CASE);
		$this->intake->method('fileOnCase')->willReturn(IntakeLog::OUTCOME_CASE);
	}//end setUp()

	/**
	 * The controller, built from whatever the test configured.
	 *
	 * @return MailIntakeController The surface under test.
	 */
	private function controller(): MailIntakeController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('intaker');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$pipeline = $this->createMock(FilterPipeline::class);
		$pipeline->method('declaredOrder')->willReturn(['blocked-sender', 'auto-reply']);

		$junkRules = $this->createMock(JunkRules::class);
		$junkRules->method('rules')->willReturn(
			[['name' => 'spam-header', 'description' => 'The mail server marked it as spam.']]
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14T12:00:00+00:00'));

		return new MailIntakeController(
			$this->createMock(IRequest::class),
			$this->log,
			$this->policy,
			$pipeline,
			$junkRules,
			$this->createMock(BounceAction::class),
			$this->createMock(MoveAction::class),
			$this->intake,
			$session,
			$time,
		);
	}//end controller()

	/**
	 * One stored entry, as the log answers it.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'entry-1',
				'accountId' => 7,
				'mailbox' => 'INBOX',
				'uid' => 12,
				'mailMessageId' => '<1@voorbeeld.nl>',
				'sender' => 'aanvrager@voorbeeld.nl',
				'subject' => 'Bezwaar',
				'outcome' => IntakeLog::OUTCOME_QUARANTINED,
				'original' => "From: aanvrager@voorbeeld.nl\r\n\r\ntekst",
			],
			$overrides
		);
	}//end entry()

	/**
	 * 🔴 No endpoint answers a reader without the intake role.
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesAReaderWithoutTheIntakeRole(): void {
		$this->policy = $this->createMock(IntakePolicy::class);
		$this->policy->method('mayRunIntake')->willReturn(false);

		$this->log->expects(self::never())->method('search');
		$this->log->expects(self::never())->method('find');

		$controller = $this->controller();
		$responses = [
			'index' => $controller->index(),
			'show' => $controller->show(entryId: 'entry-1'),
			'release' => $controller->release(entryId: 'entry-1'),
			'junk' => $controller->junk(entryId: 'entry-1', junk: false),
			'bounce' => $controller->bounce(entryId: 'entry-1', address: 'info@elders.nl'),
			'move' => $controller->move(entryId: 'entry-1', target: 'Archief'),
		];

		foreach ($responses as $name => $response) {
			self::assertSame(
				Http::STATUS_FORBIDDEN,
				$response->getStatus(),
				$name . ' must refuse a reader without the intake role.'
			);
		}
	}//end testEveryEndpointRefusesAReaderWithoutTheIntakeRole()

	/**
	 * The log answers its entries, the filter order and the readable rules.
	 *
	 * @return void
	 */
	public function testTheLogAnswersTheFilterOrderAndTheRules(): void {
		$body = $this->controller()->index()->getData();

		self::assertArrayHasKey('results', $body);
		self::assertSame(['blocked-sender', 'auto-reply'], $body['filterOrder']);
		self::assertSame('spam-header', $body['junkRules'][0]['name'], 'A junk rule is readable.');
	}//end testTheLogAnswersTheFilterOrderAndTheRules()

	/**
	 * Searching by sender narrows the read to that address.
	 *
	 * @return void
	 */
	public function testAReadIsNarrowedBySender(): void {
		$seen = [];
		$this->log = $this->createMock(IntakeLog::class);
		$this->log->method('search')->willReturnCallback(
			function (array $filters) use (&$seen): array {
				$seen = $filters;

				return [];
			}
		);

		$this->controller()->index(sender: 'Aanvrager <aanvrager@voorbeeld.nl>');

		self::assertSame('aanvrager@voorbeeld.nl', $seen['sender'], 'A display name is not part of the address.');
	}//end testAReadIsNarrowedBySender()

	/**
	 * An entry nothing holds is a 404, not an empty success.
	 *
	 * @return void
	 */
	public function testAMissingEntryIsNotFound(): void {
		$this->log->method('find')->willReturn(null);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show(entryId: 'gone')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->release(entryId: 'gone')->getStatus());
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->junk(entryId: 'gone', junk: false)->getStatus()
		);
	}//end testAMissingEntryIsNotFound()

	/**
	 * Releasing records who did it and when, and files the message.
	 *
	 * @return void
	 */
	public function testAReleasedMessageBecomesACaseAndTheReleaseIsRecorded(): void {
		$this->log->method('find')->willReturn($this->entry());
		$this->intake->expects(self::once())->method('fileOnCase');

		$response = $this->controller()->release(entryId: 'entry-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(IntakeLog::OUTCOME_RELEASED, $this->amended['outcome']);
		self::assertSame('intaker', $this->amended['releasedBy'], 'A release names the person who made it.');
		self::assertNotSame('', (string)$this->amended['releasedAt']);
	}//end testAReleasedMessageBecomesACaseAndTheReleaseIsRecorded()

	/**
	 * Only a held message can be released, so a filed one is refused.
	 *
	 * @return void
	 */
	public function testReleasingSomethingThatWasNeverHeldIsRefused(): void {
		$this->log->method('find')->willReturn($this->entry(['outcome' => IntakeLog::OUTCOME_CASE]));
		$this->intake->expects(self::never())->method('fileOnCase');

		self::assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller()->release(entryId: 'entry-1')->getStatus()
		);
	}//end testReleasingSomethingThatWasNeverHeldIsRefused()

	/**
	 * 🔴 A correction re-runs the pipeline AND is written down.
	 *
	 * @return void
	 */
	public function testMarkingAMessageNotJunkSendsItBackThroughThePipeline(): void {
		$this->log->method('find')->willReturn($this->entry(['junkRule' => 'spam-header']));
		$this->intake->expects(self::once())->method('process');

		$response = $this->controller()->junk(entryId: 'entry-1', junk: false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('', $this->amended['junkRule'], 'The wrong verdict is cleared.');
		self::assertStringContainsString('intaker', (string)$this->amended['reason'], 'And who corrected it.');
	}//end testMarkingAMessageNotJunkSendsItBackThroughThePipeline()

	/**
	 * Marking a message junk by hand holds it and records the correction.
	 *
	 * @return void
	 */
	public function testMarkingAMessageJunkHoldsItAndNamesTheHand(): void {
		$this->log->method('find')->willReturn($this->entry(['outcome' => IntakeLog::OUTCOME_CASE]));
		$this->intake->expects(self::never())->method('process');

		$this->controller()->junk(entryId: 'entry-1', junk: true);

		self::assertSame(IntakeLog::OUTCOME_QUARANTINED, $this->amended['outcome']);
		self::assertStringContainsString('intaker', (string)$this->amended['junkRule'], 'The rule names the hand.');
	}//end testMarkingAMessageJunkHoldsItAndNamesTheHand()
}//end class
