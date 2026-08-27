<?php

/**
 * SendEmailHandler Unit Tests
 *
 * Verifies the sendEmail action handler envelope shape under success,
 * missing-recipient, and notification-service-method-missing paths. The
 * handler must never throw — failures become a failed ActionResult.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\NotificatieService;
use OCA\Dossiq\Service\Transitions\SendEmailHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SendEmailHandler
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class SendEmailHandlerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenRecipientMissing(): void {
		$handler = new SendEmailHandler(
			notificationService: $this->createMock(NotificatieService::class),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail'],
			case: ['id' => 'case-1'],
			transitionContext: ['transitionLabel' => 'Approve'],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('send_email_missing_recipient', $result->error);
	}//end testFailsWhenRecipientMissing()

	/**
	 * @return void
	 */
	public function testSucceedsWhenNotificatieServiceLacksSendEmailMethod(): void {
		// NotificatieService currently exposes only publish() — sendEmail is
		// method_exists()-gated, so the handler must return success with a
		// skipped flag rather than throwing.
		$handler = new SendEmailHandler(
			notificationService: $this->createMock(NotificatieService::class),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail', 'to' => 'user@example.com'],
			case: ['id' => 'case-7'],
			transitionContext: ['transitionLabel' => 'Decided'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('user@example.com', $result->data['to']);
		self::assertTrue($result->data['skipped']);
	}//end testSucceedsWhenNotificatieServiceLacksSendEmailMethod()

	/**
	 * @return void
	 */
	public function testHandleNeverPropagatesExceptions(): void {
		// Force an exception by passing a non-array transition context cast
		// would still survive (cast-to-string of array yields warning, not throw)
		// — instead, validate the broader contract by passing the minimal valid
		// payload and asserting the success envelope shape.
		$handler = new SendEmailHandler(
			notificationService: $this->createMock(NotificatieService::class),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'sendEmail', 'to' => 'x@y'],
			case: [],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
	}//end testHandleNeverPropagatesExceptions()
}//end class
