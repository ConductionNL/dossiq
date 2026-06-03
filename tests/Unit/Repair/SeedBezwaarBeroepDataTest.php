<?php

/**
 * SeedBezwaarBeroepData Repair Step Unit Tests
 *
 * Tests for the SeedBezwaarBeroepData repair step that seeds bezwaar/beroep
 * case types into OpenRegister during app installation or upgrade.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\Procest\Repair\SeedBezwaarBeroepData;
use OCA\Procest\Service\SeedDataService;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SeedBezwaarBeroepData repair step.
 *
 * @covers \OCA\Procest\Repair\SeedBezwaarBeroepData
 */
class SeedBezwaarBeroepDataTest extends TestCase
{

    /**
     * The mocked seed data service.
     *
     * @var SeedDataService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SeedDataService $seedDataService;

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The mocked migration output.
     *
     * @var IOutput|\PHPUnit\Framework\MockObject\MockObject
     */
    private IOutput $output;

    /**
     * The repair step under test.
     *
     * @var SeedBezwaarBeroepData
     */
    private SeedBezwaarBeroepData $repairStep;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->seedDataService = $this->createMock(SeedDataService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->output          = $this->createMock(IOutput::class);

        $this->repairStep = new SeedBezwaarBeroepData(
            $this->seedDataService,
            $this->settingsService,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test that getName returns a non-empty string description.
     *
     * @return void
     */
    public function testGetNameReturnsNonEmptyString(): void
    {
        $name = $this->repairStep->getName();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);

    }//end testGetNameReturnsNonEmptyString()


    /**
     * Test that getName contains relevant keywords.
     *
     * @return void
     */
    public function testGetNameDescribesBezwaarBeroep(): void
    {
        $name = $this->repairStep->getName();

        // The name should mention bezwaar/beroep so it is recognizable in logs.
        $this->assertTrue(
            stripos($name, 'bezwaar') !== false || stripos($name, 'beroep') !== false,
            "Repair step name should mention 'bezwaar' or 'beroep', got: {$name}"
        );

    }//end testGetNameDescribesBezwaarBeroep()


    /**
     * Test that run() skips seeding when OpenRegister is not available.
     *
     * @return void
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(false);

        // seedBezwaarBeroepData must NOT be called when OpenRegister is off.
        $this->seedDataService
            ->expects($this->never())
            ->method('seedBezwaarBeroepData');

        $this->output
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('not available'));

        $this->repairStep->run($this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()


    /**
     * Test that run() calls seedBezwaarBeroepData when OpenRegister is available.
     *
     * @return void
     */
    public function testRunCallsSeedServiceWhenOpenRegisterAvailable(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->seedDataService
            ->expects($this->once())
            ->method('seedBezwaarBeroepData')
            ->willReturn([
                'success'     => true,
                'caseTypes'   => 2,
                'statusTypes' => 8,
                'roleTypes'   => 4,
                'workflows'   => 2,
                'skipped'     => 0,
            ]);

        $this->repairStep->run($this->output);

    }//end testRunCallsSeedServiceWhenOpenRegisterAvailable()


    /**
     * Test that run() logs an info message on successful seeding.
     *
     * @return void
     */
    public function testRunOutputsInfoOnSuccess(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->seedDataService
            ->method('seedBezwaarBeroepData')
            ->willReturn([
                'success'     => true,
                'caseTypes'   => 2,
                'statusTypes' => 8,
                'roleTypes'   => 4,
                'workflows'   => 2,
                'skipped'     => 0,
            ]);

        // Expect info() to be called at least once.
        $this->output
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->repairStep->run($this->output);

    }//end testRunOutputsInfoOnSuccess()


    /**
     * Test that run() handles unexpected exceptions gracefully.
     *
     * @return void
     */
    public function testRunHandlesExceptionsGracefully(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->seedDataService
            ->method('seedBezwaarBeroepData')
            ->willThrowException(new \RuntimeException('Unexpected error'));

        // run() must not throw — exceptions should be caught and logged.
        $this->output
            ->expects($this->once())
            ->method('warning');

        $this->repairStep->run($this->output);

    }//end testRunHandlesExceptionsGracefully()


}//end class
