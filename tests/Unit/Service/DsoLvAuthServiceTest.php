<?php

/**
 * DsoLvAuthService Unit Tests
 *
 * Tests for the DSO-LV authentication service: bearer token provision
 * and missing-config warning behaviour.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DsoLvAuthService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DsoLvAuthService.
 *
 * @covers \OCA\Dossiq\Service\DsoLvAuthService
 */
class DsoLvAuthServiceTest extends TestCase {

	/**
	 * The IAppConfig mock.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var DsoLvAuthService
	 */
	private DsoLvAuthService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new DsoLvAuthService(
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that getAuthHeaders returns Bearer token when configured.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGetAuthHeadersReturnsBearerTokenWhenConfigured(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('test-bearer-token-abc123');

		$headers = $this->service->getAuthHeaders();

		$this->assertSame(
			['Authorization' => 'Bearer test-bearer-token-abc123'],
			$headers
		);
	}//end testGetAuthHeadersReturnsBearerTokenWhenConfigured()

	/**
	 * Test that getAuthHeaders returns empty array and logs warning when token is not set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGetAuthHeadersReturnsEmptyArrayAndLogsWarningWhenNotConfigured(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->logger
			->expects($this->once())
			->method('warning');

		$headers = $this->service->getAuthHeaders();

		$this->assertSame([], $headers);
	}//end testGetAuthHeadersReturnsEmptyArrayAndLogsWarningWhenNotConfigured()

	/**
	 * Test that isAuthConfigured returns true when a token is set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testIsAuthConfiguredReturnsTrueWhenTokenSet(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('some-token');

		$this->assertTrue($this->service->isAuthConfigured());
	}//end testIsAuthConfiguredReturnsTrueWhenTokenSet()

	/**
	 * Test that isAuthConfigured returns false when token is empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testIsAuthConfiguredReturnsFalseWhenTokenEmpty(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->assertFalse($this->service->isAuthConfigured());
	}//end testIsAuthConfiguredReturnsFalseWhenTokenEmpty()
}//end class
