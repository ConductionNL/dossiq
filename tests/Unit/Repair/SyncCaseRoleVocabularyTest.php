<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SyncCaseRoleVocabulary;
use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The upgrade step that declares this instance's role types on the case schema.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class SyncCaseRoleVocabularyTest extends TestCase {

	/**
	 * The settings, which answer whether OpenRegister is there.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The vocabulary writer.
	 *
	 * @var CaseRoleVocabulary&MockObject
	 */
	private CaseRoleVocabulary&MockObject $vocabulary;

	/**
	 * What the step said.
	 *
	 * @var array<int, string>
	 */
	private array $said = [];

	/**
	 * The upgrade output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Build the step on doubles, recording everything it says.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->vocabulary = $this->createMock(originalClassName: CaseRoleVocabulary::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);
		$record = function (string $line): void {
			$this->said[] = $line;
		};
		$this->output->method('info')->willReturnCallback($record);
		$this->output->method('warning')->willReturnCallback($record);
	}//end setUp()

	/**
	 * The step under test.
	 *
	 * @return SyncCaseRoleVocabulary The step.
	 */
	private function step(): SyncCaseRoleVocabulary {
		return new SyncCaseRoleVocabulary(settingsService: $this->settings, vocabulary: $this->vocabulary);
	}//end step()

	/**
	 * An object service that runs its work as the system user, the way
	 * OpenRegister's does.
	 *
	 * @return object The object service.
	 */
	private function systemObjectService(): object {
		return new class {
			/**
			 * Whether the work ran inside runAsSystem.
			 *
			 * @var bool
			 */
			public bool $ranAsSystem = false;

			/**
			 * Run the work as the system user.
			 *
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->ranAsSystem = true;
				$work();
			}
		};
	}//end systemObjectService()

	/**
	 * The step names itself in the upgrade output.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		$this->assertStringContainsString(needle: 'role types', haystack: $this->step()->getName());
	}//end testTheStepNamesWhatItDoes()

	/**
	 * The sync runs as the system user and reports what the schema now holds.
	 *
	 * @return void
	 */
	public function testItSyncsAsTheSystemUserAndReportsTheCount(): void {
		$objects = $this->systemObjectService();
		$this->settings->method('getObjectService')->willReturn($objects);
		$this->vocabulary->expects($this->once())->method('sync')->willReturn(39);

		$this->step()->run(output: $this->output);

		$this->assertTrue(condition: $objects->ranAsSystem, message: 'the sync must run under the system identity');
		$this->assertStringContainsString(needle: '39 role(s)', haystack: implode(' ', $this->said));
	}//end testItSyncsAsTheSystemUserAndReportsTheCount()

	/**
	 * Without OpenRegister nothing is written, and the step says so.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsWritten(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->vocabulary->expects($this->never())->method('sync');

		$this->step()->run(output: $this->output);

		$this->assertStringContainsString(needle: 'OpenRegister unavailable', haystack: implode(' ', $this->said));
	}//end testWithoutOpenRegisterNothingIsWritten()

	/**
	 * A vocabulary the schema did not keep is reported, not called a success.
	 *
	 * @return void
	 */
	public function testAVocabularyThatDidNotLandIsReported(): void {
		$this->settings->method('getObjectService')->willReturn($this->systemObjectService());
		$this->vocabulary->method('sync')->willReturn(-1);

		$this->step()->run(output: $this->output);

		$this->assertStringContainsString(needle: 'was not written', haystack: implode(' ', $this->said));
	}//end testAVocabularyThatDidNotLandIsReported()

	/**
	 * A throwing sync warns rather than failing the whole upgrade.
	 *
	 * @return void
	 */
	public function testAThrowingSyncOnlyWarns(): void {
		$this->settings->method('getObjectService')->willReturn($this->systemObjectService());
		$this->vocabulary->method('sync')->willThrowException(new RuntimeException('the schema is gone'));

		$this->step()->run(output: $this->output);

		$this->assertStringContainsString(needle: 'the schema is gone', haystack: implode(' ', $this->said));
	}//end testAThrowingSyncOnlyWarns()
}//end class
