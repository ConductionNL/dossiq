<?php

/**
 * SendEmailHandler Unit Tests
 *
 * The handler used to gate its call on `method_exists()` and report success
 * when the method was absent, which it always was. These tests assert the
 * effect instead of the envelope: the mail service is called, with the
 * recipient and the body the action was configured with.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Transitions\SendEmailHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SendEmailHandler
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class SendEmailHandlerTest extends TestCase {
	/**
	 * The action sends the mail through CaseEmailService.
	 *
	 * This is the assertion the old suite did not make. A handler that
	 * dispatches nothing fails here, whatever it returns.
	 *
	 * @return void
	 */
	public function testDispatchesTheEmailThroughTheMailService(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::once())
			->method('sendEmail')
			->with('case-7', 'user@example.com', 'Decided', 'Your case was decided.')
			->willReturn(['messageId' => 'm-1']);
		$emailService->expects(self::never())->method('sendFromTemplate');

		$handler = new SendEmailHandler(emailService: $emailService, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: [
				'type' => 'sendEmail',
				'to' => 'user@example.com',
				'subject' => 'Decided',
				'body' => 'Your case was decided.',
			],
			case: ['id' => 'case-7'],
			transitionContext: ['transitionLabel' => 'Decided'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('user@example.com', $result->data['to']);
	}//end testDispatchesTheEmailThroughTheMailService()

	/**
	 * Without a subject the transition's own label is the subject.
	 *
	 * @return void
	 */
	public function testFallsBackToTheTransitionLabelAsSubject(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::once())
			->method('sendEmail')
			->with('case-7', 'user@example.com', 'Approve', '')
			->willReturn([]);

		$handler = new SendEmailHandler(emailService: $emailService, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail', 'to' => 'user@example.com'],
			case: ['id' => 'case-7'],
			transitionContext: ['transitionLabel' => 'Approve'],
		);

		self::assertTrue($result->succeeded);
	}//end testFallsBackToTheTransitionLabelAsSubject()

	/**
	 * A configured template goes down the template route, not the raw one.
	 *
	 * @return void
	 */
	public function testATemplateGoesThroughSendFromTemplate(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::once())
			->method('sendFromTemplate')
			->with('case-7', 'tpl-9', 'user@example.com')
			->willReturn(['messageId' => 'm-2']);
		$emailService->expects(self::never())->method('sendEmail');

		$handler = new SendEmailHandler(emailService: $emailService, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail', 'to' => 'user@example.com', 'template' => 'tpl-9'],
			case: ['id' => 'case-7'],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('tpl-9', $result->data['template']);
	}//end testATemplateGoesThroughSendFromTemplate()

	/**
	 * A send that throws is reported as a failure, not swallowed.
	 *
	 * REQ-STE-5-002 keeps the status change; it does not launder the send.
	 *
	 * @return void
	 */
	public function testAFailedSendIsReportedAsAFailure(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->method('sendEmail')->willThrowException(new RuntimeException('smtp down'));

		$handler = new SendEmailHandler(emailService: $emailService, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail', 'to' => 'user@example.com', 'body' => 'x'],
			case: ['id' => 'case-7'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('send_email_failed', $result->error);
	}//end testAFailedSendIsReportedAsAFailure()

	/**
	 * No recipient means nothing is sent and the action says so.
	 *
	 * @return void
	 */
	public function testFailsWhenRecipientMissing(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::never())->method('sendEmail');

		$handler = new SendEmailHandler(emailService: $emailService, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail'],
			case: ['id' => 'case-1'],
			transitionContext: ['transitionLabel' => 'Approve'],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('send_email_missing_recipient', $result->error);
	}//end testFailsWhenRecipientMissing()

	/**
	 * A case with no id cannot be mailed from, and is not reported as sent.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseHasNoId(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::never())->method('sendEmail');

		$handler = new SendEmailHandler(emailService: $emailService, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail', 'to' => 'x@example.com'],
			case: [],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('send_email_missing_case', $result->error);
	}//end testFailsWhenTheCaseHasNoId()
}//end class
