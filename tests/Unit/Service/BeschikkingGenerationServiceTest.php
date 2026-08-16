<?php

/**
 * BeschikkingGenerationService Unit Tests
 *
 * Tests for the beschikking document generation service covering template
 * selection, Docudesk availability fallback, and stub bijlage creation.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BeschikkingGenerationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BeschikkingGenerationService.
 *
 * @covers \OCA\Procest\Service\BeschikkingGenerationService
 */
class BeschikkingGenerationServiceTest extends TestCase {

	/**
	 * The IAppConfig mock.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The ContainerInterface mock.
	 *
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var BeschikkingGenerationService
	 */
	private BeschikkingGenerationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new BeschikkingGenerationService(
			appConfig: $this->appConfig,
			container: $this->container,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that constructor successfully sets required properties.
	 *
	 * The service must be instantiable with the correct dependencies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testConstructorSetsProperties(): void {
		$service = new BeschikkingGenerationService(
			appConfig: $this->appConfig,
			container: $this->container,
			logger: $this->logger,
		);

		$this->assertInstanceOf(BeschikkingGenerationService::class, $service);
	}//end testConstructorSetsProperties()

	/**
	 * Test that generateBeschikking returns a stub when Docudesk is unavailable.
	 *
	 * When the container cannot resolve DocumentService, the method should
	 * log a warning and return a successful result with a stub bijlage ID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGenerateBeschikkingWhenDocudeskUnavailableReturnsStub(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->container
			->method('get')
			->willThrowException(new \RuntimeException('Docudesk not installed'));

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->service->generateBeschikking(
			caseId: 'zaak-123',
			outcome: 'granted',
			motivation: 'Voldoet aan alle eisen.'
		);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('bijlageId', $result);
		$this->assertStringContainsString('stub', strtolower($result['message']));
	}//end testGenerateBeschikkingWhenDocudeskUnavailableReturnsStub()

	/**
	 * Test that generateBeschikking selects the correct template key based on outcome.
	 *
	 * outcome 'granted' must use dso_beschikking_template_verleend;
	 * outcome 'refused' must use dso_beschikking_template_geweigerd.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGenerateBeschikkingOutcomeSelectsCorrectTemplate(): void {
		$capturedKeys = [];

		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') use (&$capturedKeys) {
					$capturedKeys[] = $key;
					return '';
				}
			);

		$this->container
			->method('get')
			->willThrowException(new \RuntimeException('Docudesk not available'));

		$this->logger->method('warning');

		// For 'refused' outcome.
		$this->service->generateBeschikking(
			caseId: 'zaak-456',
			outcome: 'refused',
			motivation: 'Voldoet niet.'
		);

		$this->assertContains('dso_beschikking_template_geweigerd', $capturedKeys);
		$this->assertNotContains('dso_beschikking_template_verleend', $capturedKeys);

		$capturedKeys = [];

		// For 'granted' outcome.
		$this->service->generateBeschikking(
			caseId: 'zaak-789',
			outcome: 'granted',
			motivation: 'Alles in orde.'
		);

		$this->assertContains('dso_beschikking_template_verleend', $capturedKeys);
		$this->assertNotContains('dso_beschikking_template_geweigerd', $capturedKeys);
	}//end testGenerateBeschikkingOutcomeSelectsCorrectTemplate()
}//end class
