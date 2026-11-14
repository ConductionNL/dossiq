<?php

/**
 * Unit tests for StufVaultService.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\StufVaultService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Stuf\StufVaultService
 */
class StufVaultServiceTest extends TestCase {
	public function testASecretIsReadUnderTheHashedKey(): void {
		$reference = 'vault://stuf/endpoint-1';
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('getValueString')
			->with('dossiq', 'stuf.v.' . substr(hash('sha256', $reference), 0, 40), '')
			->willReturn('s3cret');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->assertSame('s3cret', (new StufVaultService($config, $logger))->resolveSecret($reference));
	}

	public function testAnEmptyReferenceReadsNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('StUF vault: empty reference, no credential available');

		$this->assertSame('', (new StufVaultService($config, $logger))->resolveSecret(''));
	}

	public function testAMissingSecretIsLoggedWithAMaskedReference(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('StUF vault: reference {ref} not present in app config', ['ref' => 'vault://stuf/end…']);

		$this->assertSame('', (new StufVaultService($config, $logger))->resolveSecret('vault://stuf/endpoint-1'));
	}

	public function testAShortReferenceIsLoggedWhole(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->anything(), ['ref' => 'ref-16-chars-xyz']);

		(new StufVaultService($config, $logger))->resolveSecret('ref-16-chars-xyz');
	}
}
