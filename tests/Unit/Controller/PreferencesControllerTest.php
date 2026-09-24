<?php

/**
 * The per-user preference endpoints, at the contract they answer on.
 *
 * The key is the whole attack surface: it is concatenated onto `pref_` and
 * handed to IConfig, so anything the sanitiser lets through names a user value
 * the caller chose. The tests below pin what a caller may reach, what it may
 * store, and what it is told when it may not.
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
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\PreferencesController
 */
class PreferencesControllerTest extends TestCase {

	/**
	 * The Nextcloud config double.
	 *
	 * @var IConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $config;

	/**
	 * The session double.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * The controller under test.
	 *
	 * @var PreferencesController
	 */
	private PreferencesController $controller;

	/**
	 * Build the controller over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new PreferencesController(
			$this->createMock(IRequest::class),
			$this->config,
			$this->userSession
		);
	}//end setUp()

	/**
	 * Put a signed-in user in the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A stored value comes back under the `pref_` namespace.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAStoredValueIsRead(): void {
		$this->signIn();
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('alice', 'dossiq', 'pref_walkthrough-seen', '')
			->willReturn('yes');

		$response = $this->controller->getPreference(key: 'walkthrough-seen');

		$this->assertSame(['value' => 'yes'], $response->getData());
	}//end testAStoredValueIsRead()

	/**
	 * An unset preference reads as null rather than as the empty string.
	 *
	 * The widgets that call this treat '' as a stored answer, so the two have
	 * to be told apart on the wire.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnUnsetPreferenceReadsAsNull(): void {
		$this->signIn();
		$this->config->method('getUserValue')->willReturn('');

		$response = $this->controller->getPreference(key: 'walkthrough-seen');

		$this->assertSame(['value' => null], $response->getData());
	}//end testAnUnsetPreferenceReadsAsNull()

	/**
	 * A key outside the safe charset is refused, not sent to IConfig.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnUnsafeKeyIsRefused(): void {
		$this->signIn();
		$this->config->expects($this->never())->method('getUserValue');

		$response = $this->controller->getPreference(key: '../../');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnUnsafeKeyIsRefused()

	/**
	 * A key with one unsafe character is refused rather than stripped, so it
	 * cannot land on the stored value of a different key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAKeyIsRefusedRatherThanRewritten(): void {
		$this->signIn();
		$this->config->expects($this->never())->method('setUserValue');

		$response = $this->controller->setPreference(key: 'case view', value: 'yes');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAKeyIsRefusedRatherThanRewritten()

	/**
	 * The keys the library sends are stored exactly as spelled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testALibraryKeyIsStoredAsSpelled(): void {
		$this->signIn();
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'dossiq', 'pref_dashboard-layout.Dashboard_1', 'yes');

		$this->controller->setPreference(key: 'dashboard-layout.Dashboard_1', value: 'yes');
	}//end testALibraryKeyIsStoredAsSpelled()

	/**
	 * A key too long to fit IConfig's column after `pref_` is refused, not
	 * truncated onto another key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnOverlongKeyIsRefused(): void {
		$this->signIn();
		$this->config->expects($this->never())->method('getUserValue');

		$response = $this->controller->getPreference(key: str_repeat('a', 60));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnOverlongKeyIsRefused()

	/**
	 * An anonymous caller is refused before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnAnonymousReadIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->expects($this->never())->method('getUserValue');

		$response = $this->controller->getPreference(key: 'walkthrough-seen');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousReadIsRefused()

	/**
	 * A write stores the value under the namespaced key and echoes it back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAWriteStoresTheValue(): void {
		$this->signIn();
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'dossiq', 'pref_walkthrough-seen', 'yes');

		$response = $this->controller->setPreference(key: 'walkthrough-seen', value: 'yes');

		$this->assertSame(['value' => 'yes'], $response->getData());
	}//end testAWriteStoresTheValue()

	/**
	 * An empty value clears the preference rather than storing ''.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnEmptyWriteClearsThePreference(): void {
		$this->signIn();
		$this->config->expects($this->once())
			->method('deleteUserValue')
			->with('alice', 'dossiq', 'pref_walkthrough-seen');
		$this->config->expects($this->never())->method('setUserValue');

		$response = $this->controller->setPreference(key: 'walkthrough-seen', value: '');

		$this->assertSame(['value' => null], $response->getData());
	}//end testAnEmptyWriteClearsThePreference()

	/**
	 * A value past the ceiling is refused, so one row cannot grow unbounded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnOversizedValueIsRefused(): void {
		$this->signIn();
		$this->config->expects($this->never())->method('setUserValue');

		$response = $this->controller->setPreference(
			key: 'walkthrough-seen',
			value: str_repeat('x', 8193)
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnOversizedValueIsRefused()

	/**
	 * An anonymous write is refused before anything is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testAnAnonymousWriteIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->expects($this->never())->method('setUserValue');

		$response = $this->controller->setPreference(key: 'walkthrough-seen', value: 'yes');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousWriteIsRefused()
}//end class
