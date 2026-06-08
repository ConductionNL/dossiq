<?php

/**
 * PortalMessageService Unit Tests
 *
 * Verifies that the message payload builder validates input, takes the sender
 * reference from the authenticated session (never the body) and stamps the
 * citizen-to-handler direction.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Zaakportaal
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Zaakportaal;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Zaakportaal\PortalMessageService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PortalMessageService.
 *
 * @covers \OCA\Procest\Service\Zaakportaal\PortalMessageService
 */
class PortalMessageServiceTest extends TestCase
{

    private PortalMessageService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new PortalMessageService(
            $this->createMock(SettingsService::class),
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * A missing case id is rejected.
     *
     * @return void
     */
    public function testMissingCaseIdRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->buildPayload(['content' => 'Hallo'], 'subj-1');
    }//end testMissingCaseIdRejected()

    /**
     * An empty message body is rejected.
     *
     * @return void
     */
    public function testEmptyContentRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->buildPayload(['caseId' => 'z1', 'content' => '   '], 'subj-1');
    }//end testEmptyContentRejected()

    /**
     * An overly long message body is rejected.
     *
     * @return void
     */
    public function testTooLongContentRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->buildPayload(
            ['caseId' => 'z1', 'content' => str_repeat('a', PortalMessageService::MAX_CONTENT_LENGTH + 1)],
            'subj-1'
        );
    }//end testTooLongContentRejected()

    /**
     * The authenticated sender reference always wins over a body-supplied one.
     *
     * @return void
     */
    public function testSenderRefFromSessionNotBody(): void
    {
        $payload = $this->service->buildPayload(
            ['caseId' => 'z1', 'content' => 'Vraag', 'senderRef' => 'attacker'],
            'subj-real'
        );

        $this->assertSame('subj-real', $payload['senderRef']);
        $this->assertSame('citizen_to_handler', $payload['direction']);
        $this->assertSame('z1', $payload['caseId']);
    }//end testSenderRefFromSessionNotBody()
}//end class
