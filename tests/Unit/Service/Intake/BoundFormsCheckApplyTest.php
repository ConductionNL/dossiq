<?php

/**
 * Bound forms judged against a case type, and the judgement handed to OpenRegister.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\BoundFormsCheck;
use OCA\Dossiq\Service\Intake\IntakeFormBindingCheck;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Intake\BoundFormsCheck
 */
class BoundFormsCheckApplyTest extends TestCase {

	/**
	 * The settings, answering the case type schema and the OpenRegister dependents check.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The dispatcher, which must stay silent where nothing is asked.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The binding check.
	 *
	 * @var IntakeFormBindingCheck&MockObject
	 */
	private IntakeFormBindingCheck&MockObject $check;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the check over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'case_type_schema') ? 'caseType' : ''
		);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->check = $this->createMock(IntakeFormBindingCheck::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The check under test.
	 *
	 * @return BoundFormsCheck
	 */
	private function forms(): BoundFormsCheck {
		return new BoundFormsCheck($this->settings, $this->dispatcher, $this->createMock(IAppConfig::class), $this->check, $this->logger);
	}//end forms()

	/**
	 * An assessment with forms to act on.
	 *
	 * @return array<string, mixed>
	 */
	private function assessment(): array {
		return [
			'refused' => false,
			'schema' => 'case',
			'affected' => [
				['id' => 'form-1', 'app' => 'opencatalogi', 'title' => 'Melding', 'break' => 'unpublish', 'findings' => []],
				['id' => 'form-2', 'app' => 'opencatalogi', 'title' => 'Klacht', 'break' => 'unpublish', 'findings' => []],
			],
		];
	}//end assessment()

	/**
	 * A row without `@self` is recognised by its own `schema` field, by slug or by path.
	 *
	 * @return void
	 */
	public function testARowWithoutSelfIsRecognisedByItsSchemaField(): void {
		$forms = $this->forms();

		self::assertTrue($forms->isCaseType(object: ['schema' => 'caseType']));
		self::assertTrue($forms->isCaseType(object: ['@self' => 'flat', 'schema' => 'registers/7/caseType']));
		self::assertTrue($forms->isCaseType(object: ['@self' => ['schema' => ''], 'schema' => 'caseType']));
		self::assertFalse($forms->isCaseType(object: ['schema' => 'case']));
		self::assertFalse($forms->isCaseType(object: ['schema' => 'mycaseType']));
	}//end testARowWithoutSelfIsRecognisedByItsSchemaField()

	/**
	 * Without the case schema there are no forms to ask for, and no event is sent.
	 *
	 * @return void
	 */
	public function testWithoutTheCaseSchemaNothingIsAsked(): void {
		$this->check->method('caseSchema')->willReturn(null);
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		self::assertSame(
			['refused' => false, 'schema' => '', 'affected' => []],
			$this->forms()->assess(caseType: ['id' => 'ct-1'])
		);
	}//end testWithoutTheCaseSchemaNothingIsAsked()

	/**
	 * Nothing affected means nothing to hand over, and OpenRegister is not looked up.
	 *
	 * @return void
	 */
	public function testNothingAffectedHandsNothingOver(): void {
		$this->settings->expects(self::never())->method('getOpenRegisterClass');

		self::assertSame([], $this->forms()->apply(assessment: ['refused' => false, 'schema' => 'case', 'affected' => []]));
		self::assertSame([], $this->forms()->apply(assessment: []));
	}//end testNothingAffectedHandsNothingOver()

	/**
	 * An OpenRegister that cannot unpublish is reported with the forms it leaves, and nothing is answered.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterThatCannotUnpublishIsReported(): void {
		$this->settings->expects(self::exactly(2))->method('getOpenRegisterClass')
			->with(BoundFormsCheck::DEPENDENTS_CHECK)
			->willReturnOnConsecutiveCalls(null, new \stdClass());
		$this->logger->expects(self::exactly(2))->method('error')->with(
			'Dossiq intake: published forms no longer fit their case type and OpenRegister cannot unpublish them',
			['forms' => ['form-1', 'form-2']]
		);

		self::assertSame([], $this->forms()->apply(assessment: $this->assessment()));
		self::assertSame([], $this->forms()->apply(assessment: $this->assessment()));
	}//end testAnOpenRegisterThatCannotUnpublishIsReported()

	/**
	 * The assessment is handed to OpenRegister whole; an answer that is no list is read as no outcomes.
	 *
	 * @return void
	 */
	public function testTheAssessmentIsHandedToOpenRegister(): void {
		$dependents = new class {
			/** @var array<int, array<string, mixed>> */
			public array $applied = [];

			/** @var mixed */
			public mixed $answer = null;

			/**
			 * @param array<string, mixed> $assessment The assessment.
			 *
			 * @return mixed
			 */
			public function apply(array $assessment): mixed {
				$this->applied[] = $assessment;
				return $this->answer;
			}
		};
		$this->settings->method('getOpenRegisterClass')->willReturn($dependents);
		$this->logger->expects(self::never())->method('error');

		$dependents->answer = 'done';
		self::assertSame([], $this->forms()->apply(assessment: $this->assessment()));

		$dependents->answer = ['form-1' => ['id' => 'form-1', 'outcome' => 'unpublished']];
		self::assertSame([['id' => 'form-1', 'outcome' => 'unpublished']], $this->forms()->apply(assessment: $this->assessment()));
		self::assertSame([$this->assessment(), $this->assessment()], $dependents->applied);
	}//end testTheAssessmentIsHandedToOpenRegister()
}//end class
