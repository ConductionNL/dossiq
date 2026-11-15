<?php

/**
 * Unit tests for the repair step that removes dossiq's stored mailbox credentials.
 *
 * Decision D12 moved the mail account to Nextcloud Mail, so the credential
 * LEAVES dossiq rather than moving inside it (design D-2). A password nobody
 * reads any more is still a password in a database backup, which is why the
 * upgrade deletes it instead of carrying it forward.
 *
 * THE TWO NAMESPACES ARE THE POINT. `MigrateAppConfigKeys` copies every key
 * from the `procest` namespace to `dossiq`, so a step that reaches only the new
 * one deletes the copy and leaves the original behind, reports a number, and
 * looks exactly like a step that worked.
 * {@see self::testDeletesTheCredentialFromTheOldNamespaceToo} is the test that
 * would fail if anyone narrowed it back to one namespace.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RetireImapCredentials;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the deletion, the key it keeps, both namespaces, and the failure path.
 *
 * @covers \OCA\Dossiq\Repair\RetireImapCredentials
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class RetireImapCredentialsTest extends TestCase {

	/**
	 * The in-memory app-config store: app id => key => value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $store = [];

	/**
	 * Keys whose deletion throws, to prove the step survives one.
	 *
	 * @var array<int, string>
	 */
	private array $deleteThrowsFor = [];

	/**
	 * Reset the store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->deleteThrowsFor = [];
	}//end setUp()

	/**
	 * A step reading and writing the in-memory store.
	 *
	 * @return RetireImapCredentials The step under test.
	 */
	private function step(): RetireImapCredentials {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturnCallback(
			function (string $app, string $key): bool {
				return isset($this->store[$app][$key]);
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				if (in_array($key, $this->deleteThrowsFor, true) === true) {
					throw new RuntimeException('the configuration table is unreadable');
				}

				unset($this->store[$app][$key]);
			}
		);

		return new RetireImapCredentials(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * Every stored connection value goes, and the password with it.
	 *
	 * @return void
	 */
	public function testDeletesEveryRetiredKey(): void {
		$this->store = [
			'dossiq' => [
				'email_imap_password' => 'hunter2',
				'email_imap_username' => 'postbus@example.nl',
				'email_imap_host' => 'imap.example.nl',
				'email_imap_port' => '993',
				'email_imap_encryption' => 'ssl',
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame([], $this->store['dossiq'], 'Every retired connection value must be gone.');
	}//end testDeletesEveryRetiredKey()

	/**
	 * The folder to read stays, because that is still dossiq's question.
	 *
	 * @return void
	 */
	public function testKeepsTheFolderTheInstanceNamed(): void {
		$this->store = [
			'dossiq' => [
				'email_imap_password' => 'hunter2',
				'email_imap_folder' => 'Postbus/Inkomend',
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame(
			['email_imap_folder' => 'Postbus/Inkomend'],
			$this->store['dossiq'],
			'The folder intake reads is not a credential and must survive.'
		);
	}//end testKeepsTheFolderTheInstanceNamed()

	/**
	 * A credential left in the pre-rename namespace is just as readable.
	 *
	 * @return void
	 */
	public function testDeletesTheCredentialFromTheOldNamespaceToo(): void {
		$this->store = [
			'dossiq' => ['email_imap_password' => 'hunter2'],
			'procest' => ['email_imap_password' => 'hunter2'],
		];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame([], $this->store['procest'], 'The copy under the old app id must go too.');
		self::assertSame([], $this->store['dossiq'], 'The copy under the new app id must go too.');
	}//end testDeletesTheCredentialFromTheOldNamespaceToo()

	/**
	 * An instance that never stored one is left alone and says nothing.
	 *
	 * @return void
	 */
	public function testSaysNothingWhenThereWasNoCredential(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');

		$this->step()->run($output);

		self::assertSame([], $this->store, 'A fresh install has nothing to remove.');
	}//end testSaysNothingWhenThereWasNoCredential()

	/**
	 * Running twice is running once, because repair steps run on every upgrade.
	 *
	 * @return void
	 */
	public function testIsIdempotent(): void {
		$this->store = ['dossiq' => ['email_imap_password' => 'hunter2']];

		$step = $this->step();
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $this->store['dossiq'], 'The second run must find nothing and do nothing.');
	}//end testIsIdempotent()

	/**
	 * One unreadable value does not abort an install, and the rest still go.
	 *
	 * @return void
	 */
	public function testSurvivesAKeyItCannotDeleteAndRemovesTheRest(): void {
		$this->store = [
			'dossiq' => [
				'email_imap_password' => 'hunter2',
				'email_imap_host' => 'imap.example.nl',
			],
		];
		$this->deleteThrowsFor = ['email_imap_host'];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame(
			['email_imap_host' => 'imap.example.nl'],
			$this->store['dossiq'],
			'The password must go even when another value cannot be removed.'
		);
	}//end testSurvivesAKeyItCannotDeleteAndRemovesTheRest()

	/**
	 * The name an administrator reads while the upgrade runs says what happens.
	 *
	 * @return void
	 */
	public function testNamesWhatItDoes(): void {
		self::assertStringContainsString(
			'password',
			$this->step()->getName(),
			'The step name must say that the password is removed.'
		);
	}//end testNamesWhatItDoes()
}//end class
