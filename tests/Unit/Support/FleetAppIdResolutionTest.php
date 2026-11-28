<?php

/**
 * FleetAppId — which installed id answers, and what is built from it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The id-resolving half of FleetAppId, against a mocked app manager.
 *
 * The newest installed id wins, an app manager that throws for one candidate
 * does not end the search, and the enabled check and the app path both use
 * the id that was actually found.
 *
 * @covers \OCA\Dossiq\Support\FleetAppId
 */
final class FleetAppIdResolutionTest extends TestCase {

	/**
	 * An app manager that has exactly these ids installed.
	 *
	 * @param array<int, string> $installed Installed ids.
	 * @param array<int, string> $throwsFor Ids whose installed check throws.
	 *
	 * @return IAppManager&MockObject The app manager.
	 */
	private function appManager(array $installed, array $throwsFor = []): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static function (string $appId) use ($installed, $throwsFor): bool {
				if (in_array($appId, $throwsFor, true) === true) {
					throw new RuntimeException('cannot answer for ' . $appId);
				}

				return in_array($appId, $installed, true);
			}
		);

		return $appManager;
	}//end appManager()

	/**
	 * The new id wins when both are installed; the old one answers alone.
	 *
	 * @return void
	 */
	public function testTheNewestInstalledIdWins(): void {
		self::assertSame(
			'integriq',
			FleetAppId::resolve(appManager: $this->appManager(installed: ['openconnector', 'integriq']), canonical: 'integriq')
		);
		self::assertSame(
			'openconnector',
			FleetAppId::resolve(appManager: $this->appManager(installed: ['openconnector']), canonical: 'integriq')
		);
	}//end testTheNewestInstalledIdWins()

	/**
	 * A candidate the app manager cannot answer for is skipped, not fatal.
	 *
	 * @return void
	 */
	public function testACandidateThatThrowsIsSkipped(): void {
		$appManager = $this->appManager(installed: ['docudesk'], throwsFor: ['filinq']);

		self::assertSame('docudesk', FleetAppId::resolve(appManager: $appManager, canonical: 'filinq'));
		self::assertTrue(FleetAppId::isInstalled(appManager: $appManager, canonical: 'filinq'));
	}//end testACandidateThatThrowsIsSkipped()

	/**
	 * An app under none of its ids resolves to null and reads as absent; an
	 * unmapped name is looked up as itself.
	 *
	 * @return void
	 */
	public function testAnAbsentAppResolvesToNothing(): void {
		self::assertNull(FleetAppId::resolve(appManager: $this->appManager(installed: []), canonical: 'dossiq'));
		self::assertFalse(FleetAppId::isInstalled(appManager: $this->appManager(installed: []), canonical: 'dossiq'));
		self::assertSame('mail', FleetAppId::resolve(appManager: $this->appManager(installed: ['mail']), canonical: 'mail'));
	}//end testAnAbsentAppResolvesToNothing()

	/**
	 * The enabled check asks about the id that was found, and an absent app
	 * is never asked about.
	 *
	 * @return void
	 */
	public function testTheEnabledCheckUsesTheResolvedId(): void {
		$appManager = $this->appManager(installed: ['procest']);
		$appManager->expects($this->once())->method('isEnabledForUser')->with('procest')->willReturn(true);

		self::assertTrue(FleetAppId::isEnabledForUser(appManager: $appManager, canonical: 'dossiq'));

		$absent = $this->appManager(installed: []);
		$absent->expects($this->never())->method('isEnabledForUser');

		self::assertFalse(FleetAppId::isEnabledForUser(appManager: $absent, canonical: 'dossiq'));
	}//end testTheEnabledCheckUsesTheResolvedId()

	/**
	 * An enabled check that throws reads as not enabled.
	 *
	 * @return void
	 */
	public function testAnEnabledCheckThatThrowsIsNotEnabled(): void {
		$appManager = $this->appManager(installed: ['dossiq']);
		$appManager->method('isEnabledForUser')->willThrowException(new RuntimeException('no user'));

		self::assertFalse(FleetAppId::isEnabledForUser(appManager: $appManager, canonical: 'dossiq'));
	}//end testAnEnabledCheckThatThrowsIsNotEnabled()

	/**
	 * The app path is built on the installed id, with one separator before
	 * the suffix, and is null for an absent app.
	 *
	 * @return void
	 */
	public function testTheAppPathIsBuiltOnTheInstalledId(): void {
		$appManager = $this->appManager(installed: ['openconnector']);

		self::assertSame('/apps/openconnector', FleetAppId::appPath(appManager: $appManager, canonical: 'integriq'));
		self::assertSame(
			'/apps/openconnector/api/sources',
			FleetAppId::appPath(appManager: $appManager, canonical: 'integriq', suffix: '/api/sources')
		);
		self::assertSame(
			'/apps/openconnector/api/sources',
			FleetAppId::appPath(appManager: $appManager, canonical: 'integriq', suffix: 'api/sources')
		);
		self::assertNull(FleetAppId::appPath(appManager: $this->appManager(installed: []), canonical: 'integriq'));
	}//end testTheAppPathIsBuiltOnTheInstalledId()
}//end class
