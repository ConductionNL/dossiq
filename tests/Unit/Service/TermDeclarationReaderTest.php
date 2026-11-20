<?php

/**
 * TermDeclarationReader Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reading the term declarations of a case type, a case and its phases.
 *
 * @covers \OCA\Dossiq\Service\TermDeclarationReader
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Support\ObjectSearch
 */
class TermDeclarationReaderTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The object store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * Set up a configured register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->register);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['case_type_schema', '', 'caseType'],
				['case_schema', '', 'case'],
				['status_type_schema', '', 'statusType'],
			]
		);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @return TermDeclarationReader The reader.
	 */
	private function reader(): TermDeclarationReader {
		return new TermDeclarationReader(settingsService: $this->settings);
	}//end reader()

	/**
	 * Days are read from integers, floats, digit strings and ISO durations; anything else is zero.
	 *
	 * @return void
	 */
	public function testDaysReadsEveryShape(): void {
		$reader = $this->reader();

		$this->assertSame(8, $reader->days(value: 8));
		$this->assertSame(0, $reader->days(value: -4));
		$this->assertSame(3, $reader->days(value: 3.9));
		$this->assertSame(0, $reader->days(value: -1.5));
		$this->assertSame(0, $reader->days(value: null));
		$this->assertSame(0, $reader->days(value: '  '));
		$this->assertSame(42, $reader->days(value: ' 42 '));
		$this->assertSame(56, $reader->days(value: 'P8W'));
		$this->assertSame(10, $reader->days(value: 'p10d'));
		$this->assertSame(30 + 14 + 2, $reader->days(value: 'P1M2W2D'));
		$this->assertSame(0, $reader->days(value: 'P1Y'));
		$this->assertSame(0, $reader->days(value: 'eight weeks'));
	}//end testDaysReadsEveryShape()

	/**
	 * A case type's declarations are read into days, a fixed date and flags.
	 *
	 * @return void
	 */
	public function testForCaseTypeReadsEveryDeclaration(): void {
		$this->register->seed(
			schema: 'caseType',
			uuid: 'type-1',
			row: [
				'processingDeadline' => 'P8W',
				'processingDeadlineDate' => '2026-12-31T00:00:00+01:00',
				'plannedLeadTime' => '20',
				'internalTargetDays' => 15,
				'maxSuspensionDays' => 'P2W',
				'extensionPeriod' => 'P6W',
				'statutoryWarningDays' => 5,
				'plannedWarningDays' => '3',
				'chainTermDays' => 'P1M',
				'suspensionAllowed' => 'true',
				'extensionAllowed' => 1,
			]
		);

		$this->assertSame(
			[
				'leadTimeDays' => 56,
				'fixedEndDate' => '2026-12-31',
				'plannedLeadTimeDays' => 20,
				'internalTargetDays' => 15,
				'maxSuspensionDays' => 14,
				'extensionPeriodDays' => 42,
				'statutoryWarningDays' => 5,
				'plannedWarningDays' => 3,
				'chainTermDays' => 30,
				'suspensionAllowed' => true,
				'extensionAllowed' => true,
				'statutoryTermDefinition' => '',
			],
			$this->reader()->forCaseType(caseTypeId: 'type-1')
		);
	}//end testForCaseTypeReadsEveryDeclaration()

	/**
	 * A missing case type declares nothing: zero days, no date, nothing allowed.
	 *
	 * @return void
	 */
	public function testForCaseTypeOfAMissingRowDeclaresNothing(): void {
		$declarations = $this->reader()->forCaseType(caseTypeId: 'absent');

		$this->assertSame(0, $declarations['leadTimeDays']);
		$this->assertSame('', $declarations['fixedEndDate']);
		$this->assertFalse($declarations['suspensionAllowed']);
		$this->assertFalse($declarations['extensionAllowed']);
	}//end testForCaseTypeOfAMissingRowDeclaresNothing()

	/**
	 * Only true, 1, '1' and 'true' allow; a malformed date reads empty.
	 *
	 * @return void
	 */
	public function testFlagsAreStrictAndMalformedDatesAreDropped(): void {
		$this->register->seed(
			schema: 'caseType',
			uuid: 'type-2',
			row: ['suspensionAllowed' => 'yes', 'extensionAllowed' => '1', 'processingDeadlineDate' => '31-12-2026']
		);

		$declarations = $this->reader()->forCaseType(caseTypeId: 'type-2');

		$this->assertFalse($declarations['suspensionAllowed']);
		$this->assertTrue($declarations['extensionAllowed']);
		$this->assertSame('', $declarations['fixedEndDate']);
	}//end testFlagsAreStrictAndMalformedDatesAreDropped()

	/**
	 * Without OpenRegister, an id or a configured schema nothing is read.
	 *
	 * @return void
	 */
	public function testUnconfiguredSourcesDeclareNothing(): void {
		$this->assertSame(0, $this->reader()->forCaseType(caseTypeId: '')['leadTimeDays']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$reader = new TermDeclarationReader(settingsService: $settings);
		$this->assertSame(['caseType' => '', 'status' => ''], $reader->caseContext(caseId: 'case-1'));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturn('');
		$reader = new TermDeclarationReader(settingsService: $settings);
		$this->assertSame([], $reader->phasesOf(caseTypeId: 'type-1'));
	}//end testUnconfiguredSourcesDeclareNothing()

	/**
	 * A case's context reads its type and status, whether stored as ids or as objects.
	 *
	 * @return void
	 */
	public function testCaseContextReadsReferencesInEveryShape(): void {
		$this->register->seed(schema: 'case', uuid: 'c1', row: ['caseType' => ' type-1 ', 'status' => ['id' => 'st-1']]);
		$this->register->seed(schema: 'case', uuid: 'c2', row: ['caseType' => ['@self' => ['id' => 'type-2']], 'status' => []]);

		$reader = $this->reader();

		$this->assertSame(['caseType' => 'type-1', 'status' => 'st-1'], $reader->caseContext(caseId: 'c1'));
		$this->assertSame(['caseType' => 'type-2', 'status' => ''], $reader->caseContext(caseId: 'c2'));
	}//end testCaseContextReadsReferencesInEveryShape()

	/**
	 * A case's declarations are those of its own case type.
	 *
	 * @return void
	 */
	public function testForCaseReadsItsCaseType(): void {
		$this->register->seed(schema: 'case', uuid: 'c1', row: ['caseType' => 'type-1']);
		$this->register->seed(schema: 'caseType', uuid: 'type-1', row: ['processingDeadline' => 'P2W', 'suspensionAllowed' => true]);

		$declarations = $this->reader()->forCase(caseId: 'c1');

		$this->assertSame(14, $declarations['leadTimeDays']);
		$this->assertTrue($declarations['suspensionAllowed']);
	}//end testForCaseReadsItsCaseType()

	/**
	 * A status type reads its phase term, share, order and name.
	 *
	 * @return void
	 */
	public function testForStatusTypeReadsThePhase(): void {
		$this->register->seed(
			schema: 'statusType',
			uuid: 'st-1',
			row: ['phaseTermDays' => 'P1W', 'phaseTermShare' => '0.25', 'order' => '2', 'name' => ['en' => 'Intake', 'nl' => 'Intake NL']]
		);

		$this->assertSame(
			['termDays' => 7, 'share' => 0.25, 'order' => 2, 'name' => 'Intake NL'],
			$this->reader()->forStatusType(statusTypeId: 'st-1')
		);
	}//end testForStatusTypeReadsThePhase()

	/**
	 * A case type's phases come back with their ids, ordered by order.
	 *
	 * @return void
	 */
	public function testPhasesOfAreOrderedAndCarryTheirIds(): void {
		$this->register->seed(schema: 'statusType', uuid: 'st-b', row: ['caseType' => 'type-1', 'order' => 2, 'phaseTermDays' => 10, 'name' => 'Decide']);
		$this->register->seed(schema: 'statusType', uuid: 'st-a', row: ['caseType' => 'type-1', 'order' => 1, 'phaseTermDays' => 5, 'name' => 'Intake']);
		$this->register->seed(schema: 'statusType', uuid: 'st-x', row: ['caseType' => 'type-9', 'order' => 0, 'name' => 'Other']);

		$phases = $this->reader()->phasesOf(caseTypeId: 'type-1');

		$this->assertSame(['st-a', 'st-b'], array_column($phases, 'id'));
		$this->assertSame(['Intake', 'Decide'], array_column($phases, 'name'));
		$this->assertSame([5, 10], array_column($phases, 'termDays'));
	}//end testPhasesOfAreOrderedAndCarryTheirIds()

	/**
	 * A store that cannot be searched refuses as indeterminate rather than declaring no phases.
	 *
	 * @return void
	 */
	public function testUnreadablePhasesRefuse(): void {
		$this->register->missingSchemas = ['statusType'];

		try {
			$this->reader()->phasesOf(caseTypeId: 'type-1');
			$this->fail('An unreadable store must refuse.');
		} catch (RefusedException $e) {
			$this->assertSame('term-declarations-unreadable', $e->getRule());
			$this->assertSame('The phases of this case type could not be read, so its terms were not resolved.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertNotNull($e->getPrevious());
		}
	}//end testUnreadablePhasesRefuse()

	/**
	 * A store that cannot be read refuses as indeterminate rather than declaring nothing.
	 *
	 * @return void
	 */
	public function testUnreadableCaseTypeRefuses(): void {
		$this->register->refusedToCaller = ['caseType'];

		try {
			$this->reader()->forCaseType(caseTypeId: 'type-1');
			$this->fail('An unreadable case type must refuse.');
		} catch (RefusedException $e) {
			$this->assertSame('term-declarations-unreadable', $e->getRule());
			$this->assertSame('This case type could not be read, so its terms were not resolved.', $e->getSentence());
			$this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
		}
	}//end testUnreadableCaseTypeRefuses()
}//end class
