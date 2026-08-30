<?php

/**
 * PdokService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/gis-integration/tasks.md
 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Pdok\PdokLocatieserverService;
use OCA\Dossiq\Service\PdokService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\PdokService
 */
class PdokServiceTest extends TestCase {
	private function makeService(
		?PdokLocatieserverService $locatieserver = null,
		bool $openconnectorInstalled = true,
		string $flagValue = '0',
	): PdokService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app === 'openconnector' ? $openconnectorInstalled : false,
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($flagValue);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/openconnector/api/pdok/parcel');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $p): string => 'http://nc.local' . $p,
		);

		return new PdokService(
			clientService: $this->createMock(IClientService::class),
			appManager: $appManager,
			appConfig: $appConfig,
			urlGenerator: $urlGenerator,
			locatieserver: $locatieserver ?? $this->createMock(PdokLocatieserverService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	public function testSearchAddressShortCircuitsBelowMinLength(): void {
		$loc = $this->createMock(PdokLocatieserverService::class);
		$loc->expects(self::never())->method('suggest');
		$svc = $this->makeService($loc);

		$this->assertSame([], $svc->searchAddress('ab'));
	}

	public function testSearchAddressDelegatesToLocatieserver(): void {
		$loc = $this->createMock(PdokLocatieserverService::class);
		$loc->expects(self::once())
			->method('suggest')
			->with('Stadhuis', [], 10)
			->willReturn(['response' => ['docs' => [['weergavenaam' => 'Stadhuis 1, Tilburg']]]]);
		$svc = $this->makeService($loc);

		$r = $svc->searchAddress('Stadhuis');
		$this->assertCount(1, $r);
		$this->assertSame('Stadhuis 1, Tilburg', $r[0]['weergavenaam']);
		$this->assertNull($svc->lastWarning());
	}

	public function testSearchAddressHandles503Gracefully(): void {
		$loc = $this->createMock(PdokLocatieserverService::class);
		$loc->method('suggest')->willThrowException(new RuntimeException('upstream HTTP 503'));
		$svc = $this->makeService($loc);

		$r = $svc->searchAddress('Stadhuis');
		$this->assertSame([], $r);
		$w = $svc->lastWarning();
		$this->assertNotNull($w);
		$this->assertSame('pdok.unavailable', $w['messageKey']);
		$this->assertSame(503, $w['status']);
	}

	public function testLookupAddressReturnsFirstDoc(): void {
		$loc = $this->createMock(PdokLocatieserverService::class);
		$loc->method('lookup')->willReturn([
			'response' => ['docs' => [
				['id' => 'adr-1', 'weergavenaam' => 'Conduction HQ'],
			]],
		]);
		$svc = $this->makeService($loc);

		$r = $svc->lookupAddress('adr-1');
		$this->assertSame('Conduction HQ', $r['weergavenaam']);
	}

	public function testLookupAddressReturnsNullOnEmptyId(): void {
		$svc = $this->makeService();
		$this->assertNull($svc->lookupAddress(''));
	}

	public function testSearchParcelReturnsEmptyWhenOpenconnectorMissing(): void {
		$svc = $this->makeService(openconnectorInstalled: false);
		$r = $svc->searchParcel(['perceelnummer' => '123']);
		$this->assertSame([], $r);
		$w = $svc->lastWarning();
		$this->assertNotNull($w);
		$this->assertSame('pdok.openconnector_missing', $w['messageKey']);
		$this->assertSame(404, $w['status']);
	}

	public function testGetServiceStatusReflectsFlagAndInstallState(): void {
		$svc = $this->makeService(openconnectorInstalled: true, flagValue: '1');
		$s = $svc->getServiceStatus();
		$this->assertTrue($s['openconnectorInstalled']);
		$this->assertTrue($s['featureFlagActive']);
		$this->assertNull($s['lastWarning']);
	}

	public function testGetServiceStatusReportsFlagOff(): void {
		$svc = $this->makeService(openconnectorInstalled: true, flagValue: '0');
		$s = $svc->getServiceStatus();
		$this->assertTrue($s['openconnectorInstalled']);
		$this->assertFalse($s['featureFlagActive']);
	}
}
