<?php

/**
 * InspectionChecklistService Unit Tests
 *
 * Tests for the VTH inspection checklist CRUD and result submission service.
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
 * @spec openspec/changes/vth-module/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InspectionChecklistService;
use OCA\Procest\Service\SettingsService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the InspectionChecklistService class.
 *
 * @covers \OCA\Procest\Service\InspectionChecklistService
 */
class InspectionChecklistServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked user session.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession $userSession;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var InspectionChecklistService
     */
    private InspectionChecklistService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new InspectionChecklistService(
            settingsService: $this->settingsService,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that listChecklists() throws when OpenRegister is unavailable.
     *
     * The bootstrap() helper throws RuntimeException when getObjectService()
     * returns null, so listChecklists() propagates that exception.
     *
     * @return void
     */
    public function testListChecklistsThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->listChecklists();

    }//end testListChecklistsThrowsWhenOpenRegisterUnavailable()


    /**
     * Test that createChecklist() throws when name is empty.
     *
     * @return void
     */
    public function testCreateChecklistThrowsWhenNameEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checklist name is required');

        $this->service->createChecklist(data: ['name' => '']);

    }//end testCreateChecklistThrowsWhenNameEmpty()


    /**
     * Test that createChecklist() throws when name is missing entirely.
     *
     * @return void
     */
    public function testCreateChecklistThrowsWhenNameMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checklist name is required');

        $this->service->createChecklist(data: []);

    }//end testCreateChecklistThrowsWhenNameMissing()


    /**
     * Test that validateAnswers() passes with a valid photo answer.
     *
     * @return void
     */
    public function testValidateAnswersPassesWithValidPhotoAnswer(): void
    {
        $items = [
            ['id' => 'item-1', 'type' => 'photo'],
        ];
        $answers = [
            ['itemRef' => 'item-1', 'value' => '', 'photoRef' => 'file-uuid-123'],
        ];

        // Should not throw.
        $this->service->validateAnswers(answers: $answers, items: $items);
        $this->assertTrue(true);

    }//end testValidateAnswersPassesWithValidPhotoAnswer()


    /**
     * Test that validateAnswers() throws when a photo item has no photoRef.
     *
     * @return void
     */
    public function testValidateAnswersThrowsWhenPhotoItemMissingPhotoRef(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Photo is required for checklist item: item-1');

        $items = [
            ['id' => 'item-1', 'type' => 'photo'],
        ];
        $answers = [
            ['itemRef' => 'item-1', 'value' => 'some text', 'photoRef' => ''],
        ];

        $this->service->validateAnswers(answers: $answers, items: $items);

    }//end testValidateAnswersThrowsWhenPhotoItemMissingPhotoRef()


    /**
     * Test that validateAnswers() throws when a photo item has no answer at all.
     *
     * @return void
     */
    public function testValidateAnswersThrowsWhenPhotoItemHasNoAnswer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Photo is required for checklist item: photo-item');

        $items = [
            ['id' => 'photo-item', 'type' => 'photo'],
        ];

        // No answer submitted for this item.
        $this->service->validateAnswers(answers: [], items: $items);

    }//end testValidateAnswersThrowsWhenPhotoItemHasNoAnswer()


    /**
     * Test that validateAnswers() passes for non-photo item types.
     *
     * @return void
     */
    public function testValidateAnswersPassesForNonPhotoItems(): void
    {
        $items = [
            ['id' => 'item-1', 'type' => 'boolean'],
            ['id' => 'item-2', 'type' => 'text'],
        ];
        $answers = [
            ['itemRef' => 'item-1', 'value' => 'true', 'photoRef' => ''],
            ['itemRef' => 'item-2', 'value' => 'Some text', 'photoRef' => ''],
        ];

        // Should not throw.
        $this->service->validateAnswers(answers: $answers, items: $items);
        $this->assertTrue(true);

    }//end testValidateAnswersPassesForNonPhotoItems()


    /**
     * Test that validateAnswers() handles an empty items list without error.
     *
     * @return void
     */
    public function testValidateAnswersPassesForEmptyItems(): void
    {
        $this->service->validateAnswers(answers: [], items: []);
        $this->assertTrue(true);

    }//end testValidateAnswersPassesForEmptyItems()


    /**
     * Test that validateAnswers() handles mixed photo and non-photo items correctly.
     *
     * @return void
     */
    public function testValidateAnswersMixedItemTypes(): void
    {
        $items = [
            ['id' => 'text-item', 'type' => 'text'],
            ['id' => 'photo-item', 'type' => 'photo'],
            ['id' => 'bool-item', 'type' => 'boolean'],
        ];
        $answers = [
            ['itemRef' => 'text-item', 'value' => 'hello', 'photoRef' => ''],
            ['itemRef' => 'photo-item', 'value' => '', 'photoRef' => 'uuid-photo-456'],
            ['itemRef' => 'bool-item', 'value' => 'false', 'photoRef' => ''],
        ];

        // Should not throw.
        $this->service->validateAnswers(answers: $answers, items: $items);
        $this->assertTrue(true);

    }//end testValidateAnswersMixedItemTypes()


}//end class
