<?php

/**
 * Golden record of the preference write endpoint.
 *
 * Records, per world, the status and body `setPreference` answers with and
 * every call it makes on the user config, so a refactor cannot change which
 * writes are refused or what is stored or cleared.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PreferencesController;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for PreferencesController::setPreference.
 *
 * @covers \OCA\Dossiq\Controller\PreferencesController
 */
class PreferencesWriteGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made on the user config in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the write distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testWriteAcrossWorlds(): void {
		$worlds = [
			'anonymous' => [false, 'theme', 'dark'],
			'unsafe key' => [true, 'the/me', 'dark'],
			'empty key' => [true, '', 'dark'],
			'value at the limit' => [true, 'notes', str_repeat('x', 8192)],
			'value over the limit' => [true, 'notes', str_repeat('x', 8193)],
			'stores a value' => [true, 'theme', 'dark'],
			'stores a value that reads as zero' => [true, 'count', '0'],
			'clears with an empty value' => [true, 'theme', ''],
			'page view key' => [true, 'cn_page_view:cases', 'table'],
		];

		$record = [];
		foreach ($worlds as $name => [$signedIn, $key, $value]) {
			$this->calls = [];
			$response = $this->controllerFor(signedIn: $signedIn)->setPreference($key, $value);
			$data = $response->getData();
			if (isset($data['value']) === true && strlen($data['value']) > 20) {
				$data['value'] = strlen($data['value']) . ' bytes';
			}

			$record[$name] = ['status' => $response->getStatus(), 'body' => $data, 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'preferences-write', value: $record);
	}//end testWriteAcrossWorlds()

	/**
	 * Build a controller whose caller is signed in or not.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return PreferencesController
	 */
	private function controllerFor(bool $signedIn): PreferencesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$config = $this->createMock(IConfig::class);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, mixed $value): void {
				$this->calls[] = 'set ' . $userId . ' ' . $appName . ' ' . $key . ' (' . strlen((string)$value) . ' bytes)';
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key): void {
				$this->calls[] = 'delete ' . $userId . ' ' . $appName . ' ' . $key;
			}
		);

		return new PreferencesController($this->createMock(IRequest::class), $config, $session);
	}//end controllerFor()
}//end class
