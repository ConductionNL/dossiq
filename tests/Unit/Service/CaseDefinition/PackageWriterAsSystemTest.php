<?php

/**
 * A package install sees and writes rows the caller may not.
 *
 * `occ dossiq:package:install` runs with no user, and a case is a schema with
 * authorization rules. Read under the caller's access, a case the package
 * shipped earlier looked missing and was never held for the refresh.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for PackageWriter on a schema the caller may not read.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageWriter
 * @uses \OCA\Dossiq\Service\CaseDefinition\AdoptedCaseTypeFill
 */
class PackageWriterAsSystemTest extends TestCase {

	/**
	 * The rows.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The writer under test.
	 *
	 * @var PackageWriter
	 */
	private PackageWriter $writer;

	/**
	 * A writer over a register that refuses the caller's own reads of cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new InMemoryRegister();
		$this->register->refusedToCaller = ['case'];

		$settings = $this->getMockBuilder(className: SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => '',
			}
		);

		$this->writer = new PackageWriter(logger: new NullLogger(), settings: $settings, matcher: new ExistingRowMatcher(logger: new NullLogger()));
	}//end setUp()

	/**
	 * A case the package shipped earlier is held, so the refresh reaches it.
	 *
	 * @return void
	 */
	public function testACaseShippedEarlierIsHeld(): void {
		$this->register->seed(schema: 'case', uuid: 'c-1', row: ['id' => 'c-1', 'title' => 'Melding']);

		$result = $this->writer->writePackage(
			package: 'jeugdwet',
			groups: [['schemaKey' => 'case_schema', 'rows' => [['id' => 'c-1', 'title' => 'Melding', 'legalBasis' => 'Jeugdwet artikel 2.3']]]],
		);

		self::assertSame(expected: 'success', actual: $result['status']);
		self::assertSame(expected: ['c-1'], actual: $result['skipped']);
		self::assertSame(expected: 'c-1', actual: $result['held'][0]['id']);
	}//end testACaseShippedEarlierIsHeld()

	/**
	 * A new case is written.
	 *
	 * @return void
	 */
	public function testANewCaseIsWritten(): void {
		$result = $this->writer->writePackage(
			package: 'jeugdwet',
			groups: [['schemaKey' => 'case_schema', 'rows' => [['id' => 'c-2', 'title' => 'Melding']]]],
		);

		self::assertSame(expected: 'success', actual: $result['status']);
		self::assertSame(expected: 'Melding', actual: $this->register->row(schema: 'case', uuid: 'c-2')['title']);
	}//end testANewCaseIsWritten()
}//end class
