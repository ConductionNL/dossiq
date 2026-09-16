<?php

/**
 * The first run reports the minimum an instance needs, and gates on none of it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Setup\FirstRunReadiness;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each item is a live read, and a read that raises is not done.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */
class SetupControllerReadinessTest extends TestCase {

	/**
	 * Build the readiness reader over a store and a config.
	 *
	 * @param InMemoryRegister|null $register   The object store, or null for none.
	 * @param array<string, string> $config     App-config values.
	 * @param array<string, string> $schemaKeys Configured schema slugs.
	 *
	 * @return FirstRunReadiness The reader.
	 */
	private function readiness(
		?InMemoryRegister $register,
		array $config = [],
		array $schemaKeys = ['register' => 'dossiq', 'case_type_schema' => 'caseType', 'role_schema' => 'role'],
	): FirstRunReadiness {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($schemaKeys[$key] ?? '')
		);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new FirstRunReadiness(settingsService: $settings, appConfig: $appConfig);
	}//end readiness()

	/**
	 * Every declared item comes back, with the screen that satisfies it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testEveryDeclaredItemIsReportedWithItsScreen(): void {
		$report = $this->readiness(register: new InMemoryRegister())->report();

		$this->assertCount(5, $report);
		foreach ($report as $item) {
			$this->assertNotSame('', $item['id']);
			$this->assertNotSame('', $item['title'], $item['id'] . ' is reported with no title');
			$this->assertNotSame('', $item['screen'], $item['id'] . ' leads nowhere');
			$this->assertIsBool($item['done']);
		}
	}//end testEveryDeclaredItemIsReportedWithItsScreen()

	/**
	 * A case type nobody published reads not done.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testAnInstanceWithNoPublishedCaseTypeSaysSo(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'Concept', 'isDraft' => true]);

		$this->assertFalse($this->itemById(
			report: $this->readiness(register: $register)->report(),
			id: 'published-case-type'
		)['done']);
	}//end testAnInstanceWithNoPublishedCaseTypeSaysSo()

	/**
	 * One published case type is enough, and the item re-reads to done.
	 *
	 * The same reader, the same call, a changed tree: this is what "a live read
	 * and never a stored flag" means, and a stored flag would pass the test
	 * above and fail this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testSatisfyingAnItemMakesItReadDoneOnTheNextRead(): void {
		$register = new InMemoryRegister();
		$reader = $this->readiness(register: $register);

		$this->assertFalse($this->itemById(report: $reader->report(), id: 'published-case-type')['done']);

		$register->seed(schema: 'caseType', uuid: 'ct-2', row: ['title' => 'Kapvergunning', 'isDraft' => false]);

		$this->assertTrue($this->itemById(report: $reader->report(), id: 'published-case-type')['done']);
	}//end testSatisfyingAnItemMakesItReadDoneOnTheNextRead()

	/**
	 * A role with nobody in it decides nothing, so it does not satisfy the item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testARoleWithNoHolderDoesNotCount(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'role', uuid: 'r-1', row: ['name' => 'Behandelaar', 'participant' => '']);

		$report = $this->readiness(register: $register)->report();
		$this->assertFalse($this->itemById(report: $report, id: 'role-with-holder')['done']);

		$register->seed(schema: 'role', uuid: 'r-2', row: ['name' => 'Behandelaar', 'participant' => 'anna']);

		$this->assertTrue($this->itemById(
			report: $this->readiness(register: $register)->report(),
			id: 'role-with-holder'
		)['done']);
	}//end testARoleWithNoHolderDoesNotCount()

	/**
	 * A selected mail account satisfies its item, and an empty one does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testTheMailAccountItemFollowsTheConfiguredAccount(): void {
		$this->assertFalse($this->itemById(
			report: $this->readiness(register: new InMemoryRegister())->report(),
			id: 'mail-account'
		)['done']);

		$this->assertTrue($this->itemById(
			report: $this->readiness(
				register: new InMemoryRegister(),
				config: ['email_mail_account_id' => '7']
			)->report(),
			id: 'mail-account'
		)['done']);
	}//end testTheMailAccountItemFollowsTheConfiguredAccount()

	/**
	 * An item whose read raises is not done, and names the failure.
	 *
	 * Fails closed with a status, per ADR-102. An unreadable item that reported
	 * done would be the worst of the three answers: it tells an administrator
	 * to stop looking at the one thing that is broken.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testAnItemWhoseReadThrowsIsNotDoneAndNamesTheFailure(): void {
		// No object service at all: the case-type and role reads raise.
		$report = $this->readiness(register: null)->report();

		$caseType = $this->itemById(report: $report, id: 'published-case-type');
		$this->assertFalse($caseType['done']);
		$this->assertStringContainsString('OpenRegister', $caseType['failure']);
	}//end testAnItemWhoseReadThrowsIsNotDoneAndNamesTheFailure()

	/**
	 * An item whose schema is not configured says which one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testAnUnconfiguredSchemaIsNamedInTheFailure(): void {
		$report = $this->readiness(
			register: new InMemoryRegister(),
			config: [],
			schemaKeys: ['register' => 'dossiq']
		)->report();

		$caseType = $this->itemById(report: $report, id: 'published-case-type');
		$this->assertFalse($caseType['done']);
		$this->assertStringContainsString('case_type_schema', $caseType['failure']);
	}//end testAnUnconfiguredSchemaIsNamedInTheFailure()

	/**
	 * A reported item that no reader answers is a failure, not a quiet done.
	 *
	 * The scanner above passes on the five items that DO have readers, so this
	 * runs the same path for an id that has none and asserts it is caught.
	 * Without it, adding a sixth declared item and forgetting its reader would
	 * report "not done" forever with nothing saying why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testAnItemWithNoReaderSaysSo(): void {
		$reader = $this->readiness(register: new InMemoryRegister());

		$read = (new \ReflectionClass(FirstRunReadiness::class))->getMethod('read');
		$read->setAccessible(true);
		$result = $read->invoke($reader, 'a-item-nobody-wrote-a-reader-for');

		$this->assertFalse($result['done']);
		$this->assertStringContainsString('no reader', $result['failure']);
	}//end testAnItemWithNoReaderSaysSo()

	/**
	 * One item out of a report.
	 *
	 * @param array<int, array<string, mixed>> $report The report.
	 * @param string                           $id     The item id.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @throws RuntimeException When the report does not carry it.
	 */
	private function itemById(array $report, string $id): array {
		foreach ($report as $item) {
			if ($item['id'] === $id) {
				return $item;
			}
		}

		throw new RuntimeException('the report carries no item "' . $id . '"');
	}//end itemById()

}//end class
