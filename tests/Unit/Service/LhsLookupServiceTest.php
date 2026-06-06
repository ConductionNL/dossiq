<?php

/**
 * LhsLookupService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LhsLookupService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for LhsLookupService.
 *
 * @covers \OCA\Procest\Service\LhsLookupService
 */
class LhsLookupServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var LhsLookupService
     */
    private LhsLookupService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        // No OpenRegister available — all lookups hit fallback.
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->service = new LhsLookupService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that lookup returns a cell for every valid gedrag/gevolg combination.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function testLookupReturnsCellForAllValidCombinations(): void
    {
        $gedragValues = ['A', 'B', 'C', 'D'];
        $gevolgValues = ['1', '2', '3', '4'];

        foreach ($gedragValues as $gedrag) {
            foreach ($gevolgValues as $gevolg) {
                $cell = $this->service->lookup(gedrag: $gedrag, gevolg: $gevolg);

                $this->assertIsArray($cell);
                $this->assertArrayHasKey(key: 'interventieStep', array: $cell);
                $this->assertNotEmpty(actual: $cell['interventieStep']);
            }
        }
    }//end testLookupReturnsCellForAllValidCombinations()

    /**
     * Test that lookup for B+2 returns expected intervention.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function testLookupB2ReturnsBestuurlijkeWaarschuwing(): void
    {
        $cell = $this->service->lookup(gedrag: 'B', gevolg: '2');

        $this->assertSame(expected: 'Last onder dwangsom', actual: $cell['interventieStep']);
    }//end testLookupB2ReturnsBestuurlijkeWaarschuwing()

    /**
     * Test that lookup throws for invalid gedrag.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function testLookupThrowsForInvalidGedrag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid gedrag value');

        $this->service->lookup(gedrag: 'X', gevolg: '1');
    }//end testLookupThrowsForInvalidGedrag()

    /**
     * Test that lookup throws for invalid gevolg.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function testLookupThrowsForInvalidGevolg(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid gevolg value');

        $this->service->lookup(gedrag: 'A', gevolg: '5');
    }//end testLookupThrowsForInvalidGevolg()

    /**
     * Test that lookup is case-insensitive for gedrag.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function testLookupNormalizeGedragToUppercase(): void
    {
        $cell = $this->service->lookup(gedrag: 'a', gevolg: '1');

        $this->assertSame(expected: 'A', actual: $cell['gedragRow']);
    }//end testLookupNormalizeGedragToUppercase()

    /**
     * Test that the fallback returns all 16 cells consistently.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function testFallbackCoversAll16Cells(): void
    {
        $count = 0;
        foreach (['A', 'B', 'C', 'D'] as $g) {
            foreach (['1', '2', '3', '4'] as $v) {
                $cell = $this->service->lookup(gedrag: $g, gevolg: $v);
                $this->assertNotEmpty(actual: $cell['interventieStep'], message: "Empty interventieStep for {$g}:{$v}");
                $count++;
            }
        }

        $this->assertSame(expected: 16, actual: $count);
    }//end testFallbackCoversAll16Cells()
}//end class
