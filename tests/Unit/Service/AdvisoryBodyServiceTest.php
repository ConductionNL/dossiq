<?php

/**
 * AdvisoryBodyService Unit Tests
 *
 * Tests for the Procest AdvisoryBodyService registry and search.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\AdvisoryBodyService;
use OCA\Procest\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the AdvisoryBodyService class.
 *
 * @covers \OCA\Procest\Service\AdvisoryBodyService
 */
class AdvisoryBodyServiceTest extends TestCase
{

    /**
     * Mocked SettingsService.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked IMailer.
     *
     * @var IMailer|\PHPUnit\Framework\MockObject\MockObject
     */
    private IMailer $mailer;

    /**
     * Mocked IURLGenerator.
     *
     * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private IURLGenerator $urlGenerator;

    /**
     * Mocked LoggerInterface.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * Service under test.
     *
     * @var AdvisoryBodyService
     */
    private AdvisoryBodyService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->mailer          = $this->createMock(IMailer::class);
        $this->urlGenerator    = $this->createMock(IURLGenerator::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new AdvisoryBodyService(
            $this->settingsService,
            $this->mailer,
            $this->urlGenerator,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test searchBySpecialization returns all when query is empty.
     *
     * @return void
     */
    public function testSearchBySpecializationReturnsAllOnEmptyQuery(): void
    {
        $bodies = [
            ['id' => 'body-1', 'name' => 'Brandweer', 'specializations' => ['brandveiligheid'], 'active' => true],
            ['id' => 'body-2', 'name' => 'Milieudienst', 'specializations' => ['milieu'], 'active' => true],
        ];

        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('findObjects')->willReturn($bodies);

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', 'procest'],
            ['advisory_body_schema', 'advisoryBody'],
        ]);

        $result = $this->service->searchBySpecialization(query: '');

        $this->assertCount(2, $result);

    }//end testSearchBySpecializationReturnsAllOnEmptyQuery()


    /**
     * Test searchBySpecialization ranks specialization matches higher.
     *
     * @return void
     */
    public function testSearchBySpecializationRanksSpecializationMatchesHigher(): void
    {
        $bodies = [
            [
                'id'              => 'body-1',
                'name'            => 'Milieudienst',
                'specializations' => ['milieu', 'geluid'],
                'active'          => true,
            ],
            [
                'id'              => 'body-2',
                'name'            => 'Brandweer Amsterdam-Amstelland',
                'specializations' => ['brandveiligheid', 'bouwveiligheid'],
                'active'          => true,
            ],
        ];

        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('findObjects')->willReturn($bodies);

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', 'procest'],
            ['advisory_body_schema', 'advisoryBody'],
        ]);

        $result = $this->service->searchBySpecialization(query: 'brand');

        // Brandweer should rank first (matches specialization 'brandveiligheid' + name 'Brandweer').
        $this->assertSame('body-2', $result[0]['id']);

    }//end testSearchBySpecializationRanksSpecializationMatchesHigher()


    /**
     * Test listAll returns empty array when OpenRegister unavailable.
     *
     * @return void
     */
    public function testListAllReturnsEmptyWhenUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->listAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);

    }//end testListAllReturnsEmptyWhenUnavailable()


    /**
     * Test issueSecureToken returns a 64-character hex string.
     *
     * @return void
     */
    public function testIssueSecureTokenReturns64CharHexString(): void
    {
        $token = $this->service->issueSecureToken(consultationId: 'consult-uuid-001');

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

    }//end testIssueSecureTokenReturns64CharHexString()


    /**
     * Test validateExternalBodyHasEmail returns true for external body with email.
     *
     * @return void
     */
    public function testValidateExternalBodyHasEmailReturnsTrueWhenEmailPresent(): void
    {
        $body = [
            'type'  => 'external',
            'email' => 'info@brandweer.nl',
        ];

        $result = $this->service->validateExternalBodyHasEmail(body: $body);

        $this->assertTrue($result);

    }//end testValidateExternalBodyHasEmailReturnsTrueWhenEmailPresent()


    /**
     * Test validateExternalBodyHasEmail returns false for internal body.
     *
     * @return void
     */
    public function testValidateExternalBodyHasEmailReturnsFalseForInternal(): void
    {
        $body = [
            'type'  => 'internal',
            'email' => 'info@gemeente.nl',
        ];

        $result = $this->service->validateExternalBodyHasEmail(body: $body);

        $this->assertFalse($result);

    }//end testValidateExternalBodyHasEmailReturnsFalseForInternal()


    /**
     * Test validateExternalBodyHasEmail returns false when email is empty.
     *
     * @return void
     */
    public function testValidateExternalBodyHasEmailReturnsFalseWhenEmailEmpty(): void
    {
        $body = [
            'type'  => 'external',
            'email' => '',
        ];

        $result = $this->service->validateExternalBodyHasEmail(body: $body);

        $this->assertFalse($result);

    }//end testValidateExternalBodyHasEmailReturnsFalseWhenEmailEmpty()
}//end class
