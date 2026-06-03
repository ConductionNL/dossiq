<?php

/**
 * RegisterOriRegister Repair Step Unit Tests
 *
 * Tests for the RegisterOriRegister repair step that provisions the ORI
 * (Open Raadsinformatie) register on install/upgrade.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\Procest\Repair\RegisterOriRegister;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal stub for ConfigurationService to support named parameters from
 * RegisterOriRegister::run().
 *
 * Using createMock() instead of getMockBuilder(\stdClass::class)
 * so PHPUnit generates a real interface-backed mock that honours named args.
 */
interface OriConfigurationServiceStub
{
    /**
     * Import a register configuration for an app.
     *
     * @param string $appId   The app identifier
     * @param array  $data    The configuration data
     * @param string $version The version string
     * @param bool   $force   Whether to force re-import
     *
     * @return array
     */
    public function importFromApp(string $appId, array $data, string $version, bool $force): array;
}//end interface

/**
 * Unit tests for the RegisterOriRegister repair step.
 *
 * @covers \OCA\Procest\Repair\RegisterOriRegister
 */
class RegisterOriRegisterTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked DI container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

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
     * @var RegisterOriRegister
     */
    private RegisterOriRegister $repairStep;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->output          = $this->createMock(IOutput::class);

        $this->repairStep = new RegisterOriRegister(
            settingsService: $this->settingsService,
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test getName() returns a non-empty string.
     *
     * @return void
     */
    public function testGetNameReturnsNonEmptyString(): void
    {
        $name = $this->repairStep->getName();

        $this->assertIsString($name);
        $this->assertNotEmpty($name);

    }//end testGetNameReturnsNonEmptyString()


    /**
     * Test getName() mentions ORI.
     *
     * @return void
     */
    public function testGetNameMentionsOri(): void
    {
        $name = $this->repairStep->getName();

        $this->assertStringContainsStringIgnoringCase('ORI', $name);

    }//end testGetNameMentionsOri()


    /**
     * Test run() emits a warning and returns early when OpenRegister is not available.
     *
     * @return void
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(false);

        $this->container
            ->expects($this->never())
            ->method('get');

        $this->output
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('not installed'));

        $this->repairStep->run($this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()


    /**
     * Test run() emits a warning when ConfigurationService cannot be resolved.
     *
     * @return void
     */
    public function testRunHandlesContainerException(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->container
            ->method('get')
            ->willThrowException(new \Exception('Service not found'));

        $this->output
            ->expects($this->once())
            ->method('warning');

        $this->repairStep->run($this->output);

    }//end testRunHandlesContainerException()


    /**
     * Test run() calls ConfigurationService::importFromApp() when available.
     *
     * @return void
     */
    public function testRunCallsImportFromApp(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $configurationService = $this->createMock(OriConfigurationServiceStub::class);

        $configurationService
            ->expects($this->once())
            ->method('importFromApp')
            ->willReturn(['success' => true]);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($configurationService);

        $this->output
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->repairStep->run($this->output);

    }//end testRunCallsImportFromApp()


    /**
     * Test run() handles import exceptions gracefully.
     *
     * @return void
     */
    public function testRunHandlesImportExceptionGracefully(): void
    {
        $this->settingsService
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $configurationService = $this->createMock(OriConfigurationServiceStub::class);

        $configurationService
            ->method('importFromApp')
            ->willThrowException(new \RuntimeException('Import failure'));

        $this->container
            ->method('get')
            ->willReturn($configurationService);

        $this->output
            ->expects($this->once())
            ->method('warning');

        $this->repairStep->run($this->output);

    }//end testRunHandlesImportExceptionGracefully()


}//end class
