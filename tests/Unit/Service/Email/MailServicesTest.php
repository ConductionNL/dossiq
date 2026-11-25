<?php

/**
 * MailServices: resolving Mail's services and accounts by the name the gateway gives.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailServices;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

require_once __DIR__ . '/MailCollaboratorLog.php';

/**
 * Every answer MailServices gives when Mail is absent, refuses or answers.
 *
 * @covers \OCA\Dossiq\Service\Email\MailServices
 * @uses \OCA\Dossiq\Service\Email\ThreadingLookup
 */
class MailServicesTest extends TestCase {

	/**
	 * The service names the container answers, by class name.
	 *
	 * @param array<string, mixed> $services What each name resolves to, or a Throwable to throw.
	 * @param bool|RuntimeException $installed Whether Mail is installed, or what isInstalled throws.
	 * @param MailCollaboratorLog  $log       The log.
	 *
	 * @return MailServices
	 */
	private function services(array $services, bool|RuntimeException $installed, MailCollaboratorLog $log): MailServices {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		if ($installed instanceof RuntimeException) {
			$apps->method('isInstalled')->willThrowException($installed);
		} else {
			$apps->method('isInstalled')->with('mail')->willReturn($installed);
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($services): mixed {
				$answer = ($services[$name] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new MailServices(appManager: $apps, container: $container, logger: $log, mailNames: ['mail', 'Accounts']);
	}//end services()

	/**
	 * Mail that is not installed, or an app manager that throws, resolves nothing.
	 *
	 * @return void
	 */
	public function testAbsentMailResolvesNothing(): void {
		$log = new MailCollaboratorLog();
		$absent = $this->services(services: ['Manager' => new \stdClass()], installed: false, log: $log);
		self::assertFalse(condition: $absent->isAvailable());
		self::assertNull(actual: $absent->service(name: 'Manager'));

		$throwing = $this->services(services: ['Manager' => new \stdClass()], installed: new RuntimeException('down'), log: $log);
		self::assertFalse(condition: $throwing->isAvailable());
		self::assertSame(expected: [['debug', 'Dossiq: could not ask whether the Mail app is installed']], actual: $log->records);
	}//end testAbsentMailResolvesNothing()

	/**
	 * A service the container cannot build is null with a warning; a non-object is null; a missing method is null.
	 *
	 * @return void
	 */
	public function testAServiceThatCannotBeUsedIsNull(): void {
		$log = new MailCollaboratorLog();
		$services = $this->services(
			services: ['Broken' => new RuntimeException('moved'), 'Scalar' => 'x', 'Plain' => new \stdClass()],
			installed: true,
			log: $log
		);

		self::assertNull(actual: $services->service(name: 'Broken'));
		self::assertSame(expected: 'warning', actual: $log->records[0][0]);
		self::assertNull(actual: $services->service(name: 'Scalar'));
		self::assertInstanceOf(expected: \stdClass::class, actual: $services->service(name: 'Plain'));
		self::assertNull(actual: $services->serviceWith(name: 'Plain', method: 'getSource'));
	}//end testAServiceThatCannotBeUsedIsNull()

	/**
	 * An account is read through the named account service; a bad id, a refusal or a non-object is null.
	 *
	 * @return void
	 */
	public function testAccountsComeFromTheNamedAccountService(): void {
		$account = new \stdClass();
		$accounts = new class($account) {
			/**
			 * Constructor.
			 *
			 * @param object $account The account.
			 */
			public function __construct(private object $account) {
			}

			/**
			 * Stand-in for Mail's findById().
			 *
			 * @param int $id The id.
			 * @return mixed
			 */
			public function findById(int $id): mixed {
				if ($id === 2) {
					throw new RuntimeException('no such account');
				}

				if ($id === 1) {
					return $this->account;
				}

				return null;
			}
		};
		$manager = new class() {
			/**
			 * Stand-in for Mail's getSource().
			 *
			 * @return string
			 */
			public function getSource(): string {
				return '';
			}
		};
		$log = new MailCollaboratorLog();
		$services = $this->services(services: ['Accounts' => $accounts, 'Manager' => $manager], installed: true, log: $log);

		self::assertNull(actual: $services->account(accountId: 0));
		self::assertSame(expected: $account, actual: $services->account(accountId: 1));
		self::assertNull(actual: $services->account(accountId: 2));
		self::assertSame(expected: [['warning', 'Dossiq: the configured intake account could not be read']], actual: $log->records);
		self::assertNull(actual: $services->account(accountId: 3));

		$pair = $services->accountWith(accountId: 1, name: 'Manager', method: 'getSource');
		self::assertNotNull(actual: $pair);
		self::assertSame(expected: $account, actual: $pair->account);
		self::assertSame(expected: $manager, actual: $pair->manager);
		self::assertNull(actual: $services->accountWith(accountId: 1, name: 'Manager', method: 'moveMessage'));
		self::assertNull(actual: $services->accountWith(accountId: 3, name: 'Manager', method: 'getSource'));
	}//end testAccountsComeFromTheNamedAccountService()
}//end class
