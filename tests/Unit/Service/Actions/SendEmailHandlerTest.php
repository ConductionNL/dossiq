<?php

/**
 * SendEmailHandler Unit Tests (automatic actions)
 *
 * The handler had no test at all. It resolved `NotificatieService` from the
 * container and called `sendEmail()` on it, a method that has never existed,
 * behind a `@phpstan-ignore-next-line`. The undefined-method Error landed in
 * the handler's own `catch (\Throwable)`, so every configured email became a
 * logged `email_dispatch_failed` and nothing was ever sent.
 *
 * These tests assert the effect, not the envelope: CaseEmailService is called,
 * with the case, the recipient and the rendered subject and body. A handler
 * that dispatches nothing fails here whatever it returns.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\SendEmailHandler;
use OCA\Dossiq\Service\CaseEmailService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Actions\SendEmailHandler
 *
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class SendEmailHandlerTest extends TestCase {

	/**
	 * A handler over a container that answers with the given mail service.
	 *
	 * @param CaseEmailService|null $emailService The service, or null to make
	 *                                            the container refuse.
	 *
	 * @return SendEmailHandler The handler under test.
	 */
	private function handler(?CaseEmailService $emailService): SendEmailHandler {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($emailService): object {
				if ($id === CaseEmailService::class && $emailService !== null) {
					return $emailService;
				}

				throw new class('not registered') extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		return new SendEmailHandler(container: $container, logger: new NullLogger());
	}//end handler()

	/**
	 * The action hands the mail to CaseEmailService, rendered.
	 *
	 * This is the assertion that did not exist: the recipient, the case, and
	 * both templates resolved against the case payload.
	 *
	 * @return void
	 */
	public function testDispatchesTheEmailThroughTheMailService(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::once())
			->method('sendEmail')
			->with('case-7', 'burger@example.com', 'Besluit ZK-2024-001', 'Beste Jansen, uw zaak is afgerond.')
			->willReturn(['messageId' => 'm-1']);

		$result = $this->handler($emailService)->handle(
			actionConfig: [
				'type' => 'sendEmail',
				'recipientRef' => 'indiener',
				'subjectTemplate' => 'Besluit {{case.title}}',
				'bodyTemplate' => 'Beste {{case.indiener.naam}}, uw zaak is afgerond.',
			],
			case: [
				'id' => 'case-7',
				'title' => 'ZK-2024-001',
				'indiener' => ['naam' => 'Jansen', 'email' => 'burger@example.com'],
			],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('m-1', $result->data['messageId']);
		self::assertSame('burger@example.com', $result->data['recipient']);
	}//end testDispatchesTheEmailThroughTheMailService()

	/**
	 * A dry run previews the rendered mail and sends nothing.
	 *
	 * @return void
	 */
	public function testADryRunSendsNothing(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::never())->method('sendEmail');

		$result = $this->handler($emailService)->handle(
			actionConfig: [
				'type' => 'sendEmail',
				'recipientRef' => 'email:a@example.com',
				'subjectTemplate' => 'Hoi',
				'bodyTemplate' => 'Tekst',
			],
			case: ['id' => 'case-7'],
			transitionContext: ['dryRun' => true],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Hoi', $result->data['subject']);
	}//end testADryRunSendsNothing()

	/**
	 * No recipient means nothing is sent and the action says so.
	 *
	 * @return void
	 */
	public function testFailsWhenTheRecipientCannotBeResolved(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::never())->method('sendEmail');

		$result = $this->handler($emailService)->handle(
			actionConfig: ['type' => 'sendEmail', 'recipientRef' => 'indiener'],
			case: ['id' => 'case-7'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_recipient', $result->error);
	}//end testFailsWhenTheRecipientCannotBeResolved()

	/**
	 * A case with no id cannot be mailed from, and is not reported as sent.
	 *
	 * CaseEmailService reads the case to resolve the from-address and the
	 * recipient policy, so an id is not optional.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseHasNoId(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->expects(self::never())->method('sendEmail');

		$result = $this->handler($emailService)->handle(
			actionConfig: ['type' => 'sendEmail', 'recipientRef' => 'email:a@example.com'],
			case: [],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_case_id', $result->error);
	}//end testFailsWhenTheCaseHasNoId()

	/**
	 * A mail service the container cannot produce is a failure, not a send.
	 *
	 * @return void
	 */
	public function testAnUnavailableMailServiceIsAFailure(): void {
		$result = $this->handler(null)->handle(
			actionConfig: ['type' => 'sendEmail', 'recipientRef' => 'email:a@example.com'],
			case: ['id' => 'case-7'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('email_service_unavailable', $result->error);
	}//end testAnUnavailableMailServiceIsAFailure()

	/**
	 * A send that throws is reported as a failure, not swallowed.
	 *
	 * @return void
	 */
	public function testAFailedSendIsReportedAsAFailure(): void {
		$emailService = $this->createMock(CaseEmailService::class);
		$emailService->method('sendEmail')->willThrowException(new RuntimeException('smtp down'));

		$result = $this->handler($emailService)->handle(
			actionConfig: ['type' => 'sendEmail', 'recipientRef' => 'email:a@example.com'],
			case: ['id' => 'case-7'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('email_dispatch_failed', $result->error);
	}//end testAFailedSendIsReportedAsAFailure()
}//end class
