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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The second annotation below is LOAD-BEARING, not decoration, and its name must
 * never be written with an at-sign anywhere in this prose: PHPUnit parses
 * annotations ANYWHERE in a docblock, so even a quoted mention becomes a second,
 * malformed annotation and every test in the class errors as invalid.
 *
 * Why it is needed: PdokService resolves integriq's installed id through
 * FleetAppId, so these tests execute that class. PHPUnit reports code executed
 * but not declared as RISKY, the suite runs with failOnRisky, and a risky test
 * turns a passing run into one that prints `OK, but there were issues!` and
 * exits 1. It only fires when a coverage driver is loaded, which CI has and a
 * plain local `composer test:all` does not, so the check is silently absent
 * locally and the local green means nothing about it.
 *
 * @covers \OCA\Dossiq\Service\PdokService
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class PdokServiceTest extends TestCase {
	/**
	 * The app id the mocked instance answers to, captured so the flag read can
	 * be asserted against it.
	 *
	 * @var list<string>
	 */
	private array $configReads = [];

	private function makeService(
		?PdokLocatieserverService $locatieserver = null,
		?string $installedAs = 'integriq',
		string $flagValue = '0',
	): PdokService {
		$this->configReads = [];

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app === $installedAs,
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($flagValue): string {
				$this->configReads[] = $app;
				return $flagValue;
			}
		);

		return new PdokService(
			appManager: $appManager,
			appConfig: $appConfig,
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

	/**
	 * Integriq publishes no PDOK parcel endpoint, and the service says so
	 * rather than calling one. The old code hid this behind a stale
	 * `isInstalled('openconnector')` guard: the guard answered false on every
	 * current instance, so nobody ever reached the missing route.
	 */
	public function testSearchParcelReportsThatIntegriqPublishesNoParcelEndpoint(): void {
		$svc = $this->makeService();
		$r = $svc->searchParcel(['perceelnummer' => '123']);
		$this->assertSame([], $r);
		$w = $svc->lastWarning();
		$this->assertNotNull($w);
		$this->assertSame('pdok.parcel.unsupported', $w['messageKey']);
		$this->assertSame(501, $w['status']);
	}

	public function testGetServiceStatusReflectsFlagAndInstallState(): void {
		$svc = $this->makeService(installedAs: 'integriq', flagValue: '1');
		$s = $svc->getServiceStatus();
		$this->assertTrue($s['integriqInstalled']);
		$this->assertTrue($s['featureFlagActive']);
		$this->assertNull($s['lastWarning']);
	}

	public function testGetServiceStatusReportsFlagOff(): void {
		$svc = $this->makeService(installedAs: 'integriq', flagValue: '0');
		$s = $svc->getServiceStatus();
		$this->assertTrue($s['integriqInstalled']);
		$this->assertFalse($s['featureFlagActive']);
	}

	/**
	 * The whole point of resolving rather than pinning: an instance still on
	 * the OLD id must resolve, and this test fails if the candidate list is
	 * trimmed back to one name.
	 */
	public function testStatusStillResolvesAnInstanceOnTheOldAppId(): void {
		$svc = $this->makeService(installedAs: 'openconnector', flagValue: '1');
		$s = $svc->getServiceStatus();
		$this->assertTrue($s['integriqInstalled']);
		$this->assertTrue($s['featureFlagActive']);
	}

	public function testStatusReportsAbsentWhenNeitherIdIsInstalled(): void {
		$svc = $this->makeService(installedAs: null, flagValue: '1');
		$s = $svc->getServiceStatus();
		$this->assertFalse($s['integriqInstalled']);
		$this->assertFalse(
			$s['featureFlagActive'],
			'With no PDOK app installed there is no app id to read the flag under.'
		);
	}

	/**
	 * `IAppConfig` namespaces values by app id, so the flag has to be read
	 * under the id the app is INSTALLED as. Reading it under the canonical
	 * name on an instance that still uses the old one returns the default and
	 * the shim reports permanently dormant.
	 */
	public function testFlagIsReadUnderTheIdTheAppIsActuallyInstalledAs(): void {
		$svc = $this->makeService(installedAs: 'openconnector', flagValue: '1');
		$svc->getServiceStatus();
		$this->assertSame(['openconnector'], $this->configReads);

		$svc = $this->makeService(installedAs: 'integriq', flagValue: '1');
		$svc->getServiceStatus();
		$this->assertSame(['integriq'], $this->configReads);
	}
}
