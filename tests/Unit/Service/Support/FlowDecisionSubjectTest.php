<?php

/**
 * FlowDecisionSubject: the case a decidiq flow decision is about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\CaseObjectReference;
use OCA\Dossiq\Service\Support\FlowDecisionSubject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only a decidiq-flow event whose subject is a dossiq case names a case.
 */
#[CoversClass(FlowDecisionSubject::class)]
final class FlowDecisionSubjectTest extends TestCase {

	private CaseObjectReference&MockObject $cases;

	protected function setUp(): void {
		$this->cases = $this->createMock(CaseObjectReference::class);
	}//end setUp()

	/**
	 * A flow event with these getter values.
	 *
	 * @param mixed $sourceApp The source app.
	 * @param mixed $subjectId The subject id.
	 *
	 * @return object
	 */
	private static function event(mixed $sourceApp, mixed $subjectId): object {
		return new class($sourceApp, $subjectId) {
			public function __construct(private mixed $sourceApp, private mixed $subjectId) {
			}

			public function getSourceApp(): mixed {
				return $this->sourceApp;
			}

			public function getSubjectId(): mixed {
				return $this->subjectId;
			}

			public function getSubjectRegister(): string {
				return '5';
			}

			public function getSubjectSchema(): int {
				return 12;
			}
		};
	}//end event()

	public function testAFlowDecisionOnACaseNamesTheCase(): void {
		$this->cases->expects($this->once())->method('isCase')->with('5', '12')->willReturn(true);

		$this->assertSame('case-1', (new FlowDecisionSubject($this->cases))->caseIdOf(self::event('decidiq-flow', ' case-1 ')));
	}//end testAFlowDecisionOnACaseNamesTheCase()

	public function testAFlowDecisionOnAnotherObjectNamesNoCase(): void {
		$this->cases->method('isCase')->willReturn(false);

		$this->assertSame('', (new FlowDecisionSubject($this->cases))->caseIdOf(self::event('decidiq-flow', 'obj-1')));
	}//end testAFlowDecisionOnAnotherObjectNamesNoCase()

	public function testOtherSourcesEmptySubjectsAndOtherEventsAreIgnored(): void {
		$this->cases->expects($this->never())->method('isCase');
		$subject = new FlowDecisionSubject($this->cases);

		$this->assertSame('', $subject->caseIdOf(self::event('openregister', 'case-1')));
		$this->assertSame('', $subject->caseIdOf(self::event('decidiq-flow', '  ')));
		$this->assertSame('', $subject->caseIdOf(self::event('decidiq-flow', ['case-1'])));
		$this->assertSame('', $subject->caseIdOf(new \stdClass()));
	}//end testOtherSourcesEmptySubjectsAndOtherEventsAreIgnored()
}//end class
