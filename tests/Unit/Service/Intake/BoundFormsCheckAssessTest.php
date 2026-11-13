<?php

/**
 * Bound forms judged against a case type: which forms count, how a break is chosen and how it is told.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Intake\BoundFormsCheck
 */
class BoundFormsCheckAssessTest extends TestCase {

	/**
	 * The settings, answering the case type schema slug.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The dispatcher; it hands the event the forms in $this->dependents.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The app config, answering each app's break setting from $this->breaks.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The binding check.
	 *
	 * @var IntakeFormBindingCheck&MockObject
	 */
	private IntakeFormBindingCheck&MockObject $check;

	/**
	 * The forms a listener announces as depending on the case schema.
	 *
	 * @var array<int, FormDependent>
	 */
	private array $dependents = [];

	/**
	 * Each app's configured break, by app id.
	 *
	 * @var array<string, string>
	 */
	private array $breaks = [];

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
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				foreach ($this->dependents as $form) {
					$event->addForm($form);
				}
			}
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($key === BoundFormsCheck::BREAK_SETTING)
				? ($this->breaks[$app] ?? $default)
				: 'wrong-key'
		);
		$this->check = $this->createMock(IntakeFormBindingCheck::class);
	}//end setUp()

	/**
	 * The check under test.
	 *
	 * @return BoundFormsCheck
	 */
	private function forms(): BoundFormsCheck {
		return new BoundFormsCheck(
			$this->settings,
			$this->dispatcher,
			$this->appConfig,
			$this->check,
			$this->createMock(LoggerInterface::class)
		);
	}//end forms()

	/**
	 * A case schema with a slug.
	 *
	 * @return Schema
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		return $schema;
	}//end caseSchema()

	/**
	 * A form of another app bound to the given case type.
	 *
	 * @param string      $id       The form id.
	 * @param string      $app      The owning app.
	 * @param mixed       $caseType The fixed case type, or null for no fixed block.
	 * @param string|null $audience The audience.
	 *
	 * @return FormDependent
	 */
	private function form(string $id, string $app, mixed $caseType, ?string $audience = null): FormDependent {
		$mapping = ['fields' => [['key' => $id]]];
		if ($caseType !== null) {
			$mapping['fixed'] = ['caseType' => $caseType];
		}

		return new FormDependent(
			id: $id,
			app: $app,
			title: 'Form ' . $id,
			author: 'jan',
			mapping: $mapping,
			audience: $audience
		);
	}//end form()

	/**
	 * The case type schema is matched exactly or as a path ending, from @self or the row itself.
	 *
	 * @return void
	 */
	public function testACaseTypeIsRecognisedByItsSchema(): void {
		$forms = $this->forms();

		self::assertTrue($forms->isCaseType(object: ['@self' => ['schema' => 'caseType']]));
		self::assertTrue($forms->isCaseType(object: ['@self' => ['schema' => 'dossiq/caseType']]));
		self::assertTrue($forms->isCaseType(object: ['@self' => 'not-an-array', 'schema' => 'caseType']));
		self::assertFalse($forms->isCaseType(object: ['@self' => ['schema' => 'mycaseType']]));
		self::assertFalse($forms->isCaseType(object: ['@self' => ['schema' => 'case']]));
		self::assertFalse($forms->isCaseType(object: []));
	}//end testACaseTypeIsRecognisedByItsSchema()

	/**
	 * Without a configured case type schema nothing is a case type.
	 *
	 * @return void
	 */
	public function testWithoutAConfiguredSchemaNothingIsACaseType(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('');
		$forms = new BoundFormsCheck(
			$settings,
			$this->dispatcher,
			$this->appConfig,
			$this->check,
			$this->createMock(LoggerInterface::class)
		);

		self::assertFalse($forms->isCaseType(object: ['@self' => ['schema' => '']]));
	}//end testWithoutAConfiguredSchemaNothingIsACaseType()

	/**
	 * A draft case type is never judged: no forms are asked for.
	 *
	 * @return void
	 */
	public function testADraftIsNotJudged(): void {
		$this->check->expects(self::never())->method('caseSchema');
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		self::assertSame(
			['refused' => false, 'schema' => '', 'affected' => []],
			$this->forms()->assess(caseType: ['id' => 'ct-1', 'isDraft' => true])
		);
	}//end testADraftIsNotJudged()

	/**
	 * Only another app's form bound to this case type and failing it is affected; its app picks the break.
	 *
	 * @return void
	 */
	public function testOnlyForeignFormsBoundToThisCaseTypeThatFailItAreAffected(): void {
		$this->check->method('caseSchema')->willReturn($this->caseSchema());
		$this->breaks = ['portaliq' => 'refuse', 'opencatalogi' => 'unpublish'];
		$finding = ['property' => 'name', 'code' => 'required-unmapped', 'message' => 'Name is required'];
		$this->dependents = [
			$this->form(id: 'own', app: 'dossiq', caseType: 'ct-1'),
			$this->form(id: 'unfixed', app: 'portaliq', caseType: null),
			$this->form(id: 'other-type', app: 'portaliq', caseType: 'ct-2'),
			$this->form(id: 'fits', app: 'portaliq', caseType: 'ct-1'),
			$this->form(id: 'refuses', app: 'portaliq', caseType: ' permit ', audience: 'public'),
			$this->form(id: 'unpublishes', app: 'opencatalogi', caseType: 'ct-1'),
		];
		$asked = [];
		$this->check->method('findingsOfMapping')->willReturnCallback(
			static function (array $caseType, array $mapping, ?string $audience) use (&$asked, $finding): array {
				$formId = $mapping['fields'][0]['key'];
				$asked[] = [$formId, $caseType['id'], $audience];

				return ($formId === 'fits') ? [] : [$finding];
			}
		);

		$caseType = ['id' => 'ct-1', '@self' => ['slug' => 'permit']];
		$assessment = $this->forms()->assess(caseType: $caseType);

		self::assertSame(
			[['fits', 'ct-1', null], ['refuses', 'ct-1', 'public'], ['unpublishes', 'ct-1', null]],
			$asked,
			'Only foreign forms with a fixed block naming this case type are checked, with their audience.'
		);
		self::assertTrue($assessment['refused']);
		self::assertSame('case', $assessment['schema']);
		self::assertSame(['refuses', 'unpublishes'], array_column($assessment['affected'], 'id'));
		self::assertSame(['refuse', 'unpublish'], array_column($assessment['affected'], 'break'));
		self::assertSame([[$finding], [$finding]], array_column($assessment['affected'], 'findings'));
		self::assertSame('Form refuses', $assessment['affected'][0]['title']);
		self::assertSame('portaliq', $assessment['affected'][0]['app']);
		self::assertSame($this->dependents[4], $assessment['affected'][0]['form']);
	}//end testOnlyForeignFormsBoundToThisCaseTypeThatFailItAreAffected()

	/**
	 * Forms that only unpublish do not refuse the save, and an unset break defaults to unpublish.
	 *
	 * @return void
	 */
	public function testUnpublishingFormsDoNotRefuseTheSave(): void {
		$this->check->method('caseSchema')->willReturn($this->caseSchema());
		$this->check->method('findingsOfMapping')->willReturn([['message' => 'x']]);
		$this->dependents = [$this->form(id: 'f', app: 'portaliq', caseType: 'ct-1')];

		$assessment = $this->forms()->assess(caseType: ['id' => 'ct-1']);

		self::assertFalse($assessment['refused']);
		self::assertSame(['unpublish'], array_column($assessment['affected'], 'break'));
	}//end testUnpublishingFormsDoNotRefuseTheSave()

	/**
	 * The sentence names each form and its app, using the message or else property and code.
	 *
	 * @return void
	 */
	public function testTheSentenceNamesEachFormAndWhatItLacks(): void {
		$sentence = $this->forms()->sentenceFor(
			assessment: [
				'affected' => [
					[
						'title' => 'Melding',
						'app' => 'portaliq',
						'findings' => [
							['message' => ' Name is required '],
							['property' => 'email', 'code' => 'type-mismatch'],
							'not-a-finding',
						],
					],
					['title' => 'Klacht', 'app' => 'opencatalogi', 'findings' => []],
				],
			]
		);

		self::assertSame(
			'A published form would no longer open a case of this type. '
			. '"Melding" (portaliq): Name is required email: type-mismatch "Klacht" (opencatalogi): ',
			$sentence
		);
		self::assertSame(
			'A published form would no longer open a case of this type. ',
			$this->forms()->sentenceFor(assessment: [])
		);
	}//end testTheSentenceNamesEachFormAndWhatItLacks()

	/**
	 * The listing drops the form object and renumbers the entries.
	 *
	 * @return void
	 */
	public function testTheListingDropsTheFormObject(): void {
		$listed = $this->forms()->listed(
			assessment: [
				'affected' => [
					3 => ['id' => 'a', 'break' => 'refuse', 'form' => new \stdClass()],
					7 => ['id' => 'b', 'break' => 'unpublish'],
				],
			]
		);

		self::assertSame([['id' => 'a', 'break' => 'refuse'], ['id' => 'b', 'break' => 'unpublish']], $listed);
		self::assertSame([], $this->forms()->listed(assessment: []));
	}//end testTheListingDropsTheFormObject()

	/**
	 * Every non-empty scalar id, uuid and slug names the case type once, trimmed.
	 *
	 * @return void
	 */
	public function testACaseTypeIsNamedByEveryIdItCarries(): void {
		self::assertSame(
			['ct-1', 'u-1', 'permit'],
			$this->forms()->namesOf(
				caseType: [
					'id' => ' ct-1 ',
					'uuid' => 'u-1',
					'slug' => '',
					'@self' => ['id' => 'ct-1', 'uuid' => ['nested'], 'slug' => 'permit', 'ignored' => 'x'],
				]
			)
		);
		self::assertSame(['7'], $this->forms()->namesOf(caseType: ['id' => 7, '@self' => 'not-an-array']));
		self::assertSame([], $this->forms()->namesOf(caseType: []));
	}//end testACaseTypeIsNamedByEveryIdItCarries()
}//end class
