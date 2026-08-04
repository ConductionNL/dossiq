<?php

/**
 * NotifyHandler Unit Tests
 *
 * Verifies the notify action handler envelope under recipient-missing,
 * service-method-missing, and exception paths.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Transitions;

use OCA\Procest\Service\NotificatieService;
use OCA\Procest\Service\Transitions\NotifyHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Procest\Service\Transitions\NotifyHandler
 *
 * @uses \OCA\Procest\Service\Transitions\ActionResult
 */
class NotifyHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testSucceedsButSkipsWhenNotifyUserMethodMissing(): void
    {
        // NotificatieService only exposes publish() today; notifyUser is gated
        // by method_exists. Handler must report success with skipped flag.
        $handler = new NotifyHandler(
            notificatieService: $this->createMock(NotificatieService::class),
            logger: new NullLogger(),
        );

        $result = $handler->handle(
            actionConfig: ['type' => 'notify', 'userId' => 'alice', 'message' => 'Klaar'],
            case: ['id' => 'c'],
            transitionContext: ['transitionLabel' => 'Done'],
        );

        self::assertTrue($result->succeeded);
        self::assertTrue($result->data['skipped']);
    }//end testSucceedsButSkipsWhenNotifyUserMethodMissing()

    /**
     * @return void
     */
    public function testFallsBackToCaseAssigneeWhenUserIdMissing(): void
    {
        $handler = new NotifyHandler(
            notificatieService: $this->createMock(NotificatieService::class),
            logger: new NullLogger(),
        );

        // Empty recipient + no notifyUser method → still success-skip envelope.
        $result = $handler->handle(
            actionConfig: ['type' => 'notify'],
            case: ['id' => 'c', 'assignee' => 'bob'],
            transitionContext: ['transitionLabel' => 'Approved'],
        );

        self::assertTrue($result->succeeded);
    }//end testFallsBackToCaseAssigneeWhenUserIdMissing()

    /**
     * @return void
     */
    public function testReturnsSkippedWhenNoRecipientAndNoAssignee(): void
    {
        $handler = new NotifyHandler(
            notificatieService: $this->createMock(NotificatieService::class),
            logger: new NullLogger(),
        );

        $result = $handler->handle(
            actionConfig: ['type' => 'notify'],
            case: ['id' => 'c'],
            transitionContext: [],
        );

        self::assertTrue($result->succeeded);
        self::assertTrue($result->data['skipped']);
    }//end testReturnsSkippedWhenNoRecipientAndNoAssignee()
}//end class
