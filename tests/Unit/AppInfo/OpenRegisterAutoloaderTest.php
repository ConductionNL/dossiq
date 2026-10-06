<?php

/**
 * Tests for the OpenRegister autoload prelude.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\OpenRegisterAutoloader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The prelude's whole purpose is that it CANNOT take down the caller.
 *
 * It runs inside `Application::register()`, which Nextcloud executes on EVERY
 * request. An exception escaping it would abort the whole composition root —
 * the exact failure the prelude exists to prevent — so "never throws" is the
 * contract under test, on ANY instance, with OpenRegister present or absent.
 *
 * It must also work on Nextcloud 35, which removed the private
 * `OC_App::registerAutoloading()` it used to call, so it may only use public
 * API and plain PHP.
 */
class OpenRegisterAutoloaderTest extends TestCase {
	/**
	 * Temporary fake openregister app directory.
	 *
	 * @var string
	 */
	private string $appPath;

	/**
	 * Create a fake openregister app with one class under lib/.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		OpenRegisterAutoloader::unregister();
		$this->appPath = sys_get_temp_dir().'/dossiq-or-'.bin2hex(random_bytes(4));
		mkdir($this->appPath.'/lib/Fake', 0777, true);
		file_put_contents(
			$this->appPath.'/lib/Fake/Probe.php',
			"<?php\nnamespace OCA\\OpenRegister\\Fake;\nfinal class Probe {}\n"
		);

	}//end setUp()

	/**
	 * Take the loader off the SPL chain and remove the fake app.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		OpenRegisterAutoloader::unregister();
		@unlink($this->appPath.'/lib/Fake/Probe.php');
		@rmdir($this->appPath.'/lib/Fake');
		@rmdir($this->appPath.'/lib');
		@rmdir($this->appPath);
		parent::tearDown();

	}//end tearDown()

	/**
	 * The prelude must never throw, whatever the instance looks like.
	 *
	 * This runs in both environments the suite is executed in: with Nextcloud
	 * booted (where OpenRegister may or may not be installed) and with only the
	 * OCP stubs registered (where `\OCP\Server::get()` cannot resolve
	 * anything). Both must be swallowed.
	 *
	 * @return void
	 */
	public function testRegisterNeverThrows(): void {
		$before = count(spl_autoload_functions());

		OpenRegisterAutoloader::register();

		// Reaching this line at all IS the assertion: the contract is that the
		// prelude returns control to its caller under every instance state. A
		// Throwable escaping it would fail the test here, and in production
		// would abort the whole of Application::register().
		$this->assertGreaterThan(
			expected: 0,
			actual: $before,
			message: 'The prelude must return control to its caller, never throw.'
		);

	}//end testRegisterNeverThrows()

	/**
	 * Calling the prelude twice must be free and must agree with itself.
	 *
	 * The prelude short-circuits while its loader is registered, so a second
	 * call is a no-op. `Application::register()` may run more
	 * than once in a single process, and a prelude that failed or threw on the
	 * second call would be a latent bootstrap defect.
	 *
	 * @return void
	 */
	public function testRegisterIsIdempotent(): void {
		OpenRegisterAutoloader::register();
		$afterFirst = count(spl_autoload_functions());

		OpenRegisterAutoloader::register();
		$afterSecond = count(spl_autoload_functions());

		$this->assertSame(
			expected: $afterFirst,
			actual: $afterSecond,
			message: 'A second call must not stack another autoloader — '
				. 'the prelude short-circuits while registered, so it is free '
				. 'to repeat.'
		);

	}//end testRegisterIsIdempotent()

	/**
	 * The degraded path must be swallowed, not rethrown.
	 *
	 * In production this is `openregister` on an instance where it is not
	 * installed: `IAppManager::getAppPath()` throws `AppPathNotFoundException`.
	 * The prelude MUST absorb it — a Throwable escaping here would abort the
	 * caller's entire `register()`, which is the failure the prelude exists to
	 * prevent, and it would abort it on EVERY request.
	 *
	 * The app id is a parameter for exactly this reason. Every instance this
	 * suite runs on HAS OpenRegister installed, so without an id that cannot
	 * resolve, this branch is dead code that no test can reach — and a branch
	 * no test can reach is a branch no one has ever checked.
	 *
	 * @return void
	 */
	public function testRegisterSwallowsAnAppThatCannotResolve(): void {
		$before = count(spl_autoload_functions());

		OpenRegisterAutoloader::register('an-app-that-is-not-installed');

		$this->assertSame(
			expected: $before,
			actual: count(spl_autoload_functions()),
			message: 'A prelude whose app cannot be resolved must leave the '
				. 'autoloader untouched and must not rethrow.'
		);

	}//end testRegisterSwallowsAnAppThatCannotResolve()

	/**
	 * The prelude must not reach for the private OC_App class (gone in NC 35).
	 *
	 * @return void
	 */
	public function testSourceUsesNoPrivateOcAppApi(): void {
		$source = (string) file_get_contents(__DIR__.'/../../../lib/AppInfo/OpenRegisterAutoloader.php');
		$code   = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
		$this->assertStringNotContainsString('OC_App', $code);

	}//end testSourceUsesNoPrivateOcAppApi()

	/**
	 * With OpenRegister enabled, its classes become autoloadable, exactly once.
	 *
	 * @return void
	 */
	public function testRegistersPsr4PrefixWhenEnabled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('openregister')->willReturn(true);
		$appManager->method('getAppPath')->with('openregister')->willReturn($this->appPath.'/');

		OpenRegisterAutoloader::register(appManager: $appManager);
		$afterFirst = count(spl_autoload_functions());
		OpenRegisterAutoloader::register(appManager: $appManager);

		$this->assertSame($afterFirst, count(spl_autoload_functions()));
		$this->assertTrue(class_exists('OCA\\OpenRegister\\Fake\\Probe'));

	}//end testRegistersPsr4PrefixWhenEnabled()

	/**
	 * A disabled OpenRegister is never wired, and its path is never resolved.
	 *
	 * @return void
	 */
	public function testDoesNothingWhenDisabled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(false);
		$appManager->expects($this->never())->method('getAppPath');

		$before = count(spl_autoload_functions());
		OpenRegisterAutoloader::register(appManager: $appManager);

		$this->assertSame($before, count(spl_autoload_functions()));

	}//end testDoesNothingWhenDisabled()

	/**
	 * An app manager that throws is swallowed, not rethrown.
	 *
	 * @return void
	 */
	public function testSwallowsAThrowingAppManager(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willThrowException(new \RuntimeException('boom'));

		$before = count(spl_autoload_functions());
		OpenRegisterAutoloader::register(appManager: $appManager);

		$this->assertSame($before, count(spl_autoload_functions()));

	}//end testSwallowsAThrowingAppManager()

	/**
	 * The loader only ever answers for OpenRegister's own namespace.
	 *
	 * @return void
	 */
	public function testClassFileOnlyAnswersForOpenRegister(): void {
		$this->assertSame(
			'/x/lib/Db/Schema.php',
			OpenRegisterAutoloader::classFile(appPath: '/x', class: 'OCA\\OpenRegister\\Db\\Schema')
		);
		$this->assertNull(OpenRegisterAutoloader::classFile(appPath: '/x', class: 'OCA\\Dossiq\\Foo'));
		$this->assertNull(OpenRegisterAutoloader::classFile(appPath: '/x', class: 'OCA\\OpenRegister\\'));

	}//end testClassFileOnlyAnswersForOpenRegister()
}//end class
