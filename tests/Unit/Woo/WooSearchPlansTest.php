<?php

/**
 * Woo Search Plans Test
 *
 * The search plan of a Woo request is recorded with who and when, a draft
 * counts as no plan, the configuration follows the plan without its period,
 * and a new request starts from an earlier one's configuration. Every written
 * row is validated against the real merged register schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\RecordTemplate\RecordTemplateReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseReviewerAccess;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooCorpusRefused;
use OCA\Dossiq\Woo\WooSearchPlans;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Woo\WooSearchPlans
 * @covers \OCA\Dossiq\Woo\WooCorpusRefused
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Woo\WooSources
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 */
class WooSearchPlansTest extends TestCase {

	use MakesCaseReviewerAccess;

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';
	private const EARLIER = '55555555-5555-4555-8555-555555555555';
	private const TEMPLATE = '77777777-7777-4777-8777-777777777777';

	private InMemoryRegister $register;

	private WooSearchPlans $plans;

	/**
	 * The named templates OpenRegister would answer the caller, by uuid.
	 *
	 * @var array<string, array{uuid: string, name: string, values: array<string, mixed>}>
	 */
	private array $named = [];

	/**
	 * The schema ids the reader was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $askedSchemas = [];

	/**
	 * A reader over `$this->named`.
	 *
	 * @return RecordTemplateReader The reader.
	 */
	private function templates(): RecordTemplateReader {
		$reader = $this->createMock(RecordTemplateReader::class);
		$reader->method('find')->willReturnCallback(
			function (string $uuid, string $schema): ?array {
				$this->askedSchemas[] = $schema;
				return ($this->named[$uuid] ?? null);
			}
		);

		return $reader;
	}//end templates()

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_search_plan_schema' => 'wooSearchPlan',
				'woo_request_configuration_schema' => 'wooRequestConfiguration',
			][$key] ?? $default
		);
		$this->plans = new WooSearchPlans(settingsService: $settings, templates: $this->templates(), access: $this->caseReviewerAccessOver($settings));
	}//end setUp()

	/**
	 * The scenario's plan.
	 *
	 * @return array<string, mixed> The input.
	 */
	private function input(): array {
		return [
			'custodians' => [['name' => 'Wethouder Ruimte'], ['name' => 'Afdeling Vergunningen', 'function' => 'Vergunningen']],
			'systems' => ['files', 'microsoft365', 'nowhere'],
			'periodFrom' => '2025-01-01',
			'periodTo' => '2025-12-31',
			'terms' => 'Stationsweg',
		];
	}//end input()

	/**
	 * REQ-WRC-001 "The plan is held with the request".
	 *
	 * @return void
	 */
	public function testThePlanIsHeldWithTheRequest(): void {
		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen');

		$plan = $this->plans->recorded(caseId: self::CASE_ID);
		self::assertSame(['Wethouder Ruimte', 'Afdeling Vergunningen'], $this->plans->custodianNames(plan: $plan));
		self::assertSame(['files', 'microsoft365'], $plan['systems'], 'an unknown system is dropped');
		self::assertSame('2025-01-01', $plan['periodFrom']);
		self::assertSame('2025-12-31', $plan['periodTo']);
		self::assertSame('Stationsweg', $plan['terms']);
		self::assertSame('pjansen', $plan['recordedBy']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $plan['recordedAt']);

		$real = new RealSchemaValidator();
		self::assertSame([], $real->errors(slug: 'wooSearchPlan', payload: $plan));
		$configuration = $this->register->all('wooRequestConfiguration')[0];
		self::assertSame([], $real->errors(slug: 'wooRequestConfiguration', payload: $configuration));
		self::assertArrayNotHasKey('periodFrom', $configuration, 'the configuration carries no period');
	}//end testThePlanIsHeldWithTheRequest()

	public function testRecordingAgainChangesTheSameObject(): void {
		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen');
		$changed = $this->input();
		$changed['terms'] = 'Stationsweg OR Stationsplein';
		$this->plans->record(caseId: self::CASE_ID, input: $changed, userId: 'abakker');

		self::assertCount(1, $this->register->all('wooSearchPlan'), 'one plan per case, its history is the audit trail');
		self::assertCount(1, $this->register->all('wooRequestConfiguration'));
		self::assertSame('abakker', $this->plans->recorded(caseId: self::CASE_ID)['recordedBy']);
	}//end testRecordingAgainChangesTheSameObject()

	/**
	 * REQ-CRA-011: the plan is private, so a caller off the plan's readers is not shown it,
	 * while the rule "was a plan recorded" still holds for the case.
	 *
	 * @return void
	 */
	public function testAPlanTheCallerCannotReadStillCountsAsRecordedAndIsNotShown(): void {
		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen');
		$this->register->hiddenFromCaller = ['wooSearchPlan'];

		self::assertNull($this->plans->planOf(caseId: self::CASE_ID), 'what a person is shown follows their own access');
		self::assertNotNull($this->plans->recorded(caseId: self::CASE_ID), 'the gather is not refused for want of a plan that exists');
		self::assertNull($this->plans->planOfCase(caseId: 'another-case'));
	}//end testAPlanTheCallerCannotReadStillCountsAsRecordedAndIsNotShown()

	public function testRecordingOverAPlanTheCallerCannotReadMakesNoSecondPlan(): void {
		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen');
		$this->register->hiddenFromCaller = ['wooSearchPlan'];

		$this->plans->record(caseId: self::CASE_ID, input: (['terms' => 'Stationsplein'] + $this->input()), userId: 'colleague');

		self::assertCount(1, $this->register->all('wooSearchPlan'), 'the write addresses the plan that exists; OpenRegister decides whether this caller may');
	}//end testRecordingOverAPlanTheCallerCannotReadMakesNoSecondPlan()

	public function testANewPlanIsReconciledAndAChangedOneIsNot(): void {
		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen');
		self::assertSame([['case' => self::CASE_ID, 'created' => 1]], $this->reconciledAfterCreate);

		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen');
		self::assertCount(1, $this->reconciledAfterCreate, 'a changed plan keeps the grants it has');
	}//end testANewPlanIsReconciledAndAChangedOneIsNot()

	public function testAPlanStartedFromAnEarlierRequestIsReconciled(): void {
		$this->register->seed('wooRequestConfiguration', 'conf-1', ['case' => 'earlier-case', 'custodians' => [['name' => 'A']], 'systems' => ['files'], 'terms' => 'x']);

		self::assertNotNull($this->plans->startFrom(caseId: self::CASE_ID, from: 'earlier-case'));

		self::assertSame([['case' => self::CASE_ID, 'created' => 1]], $this->reconciledAfterCreate);
	}//end testAPlanStartedFromAnEarlierRequestIsReconciled()

	public function testADraftPlanCountsAsNone(): void {
		$this->register->seed('wooSearchPlan', 'draft-1', ['case' => self::CASE_ID, 'terms' => 'x']);

		self::assertNotNull($this->plans->planOf(caseId: self::CASE_ID));
		self::assertNull($this->plans->recorded(caseId: self::CASE_ID));
	}//end testADraftPlanCountsAsNone()

	public function testAnIncompletePlanIsRefusedWithWhatIsMissing(): void {
		foreach (['custodians' => [], 'systems' => ['dropbox'], 'periodTo' => '2024-01-01', 'terms' => ' '] as $key => $value) {
			$input = $this->input();
			$input[$key] = $value;
			try {
				$this->plans->record(caseId: self::CASE_ID, input: $input, userId: 'pjansen');
				self::fail($key . ' should have been refused');
			} catch (WooCorpusRefused $refused) {
				self::assertSame(400, $refused->getStatus());
				self::assertStringStartsWith('plan_incomplete_', $refused->getMessage());
			}
		}

		self::assertSame([], $this->register->all('wooSearchPlan'));
	}//end testAnIncompletePlanIsRefusedWithWhatIsMissing()

	/**
	 * REQ-WRC-005 "Start from the last request".
	 *
	 * @return void
	 */
	public function testANewRequestCopiesTheLastConfigurationWithoutThePeriod(): void {
		$this->plans->record(caseId: self::EARLIER, input: $this->input(), userId: 'pjansen');

		$this->plans->startFrom(caseId: self::CASE_ID, from: self::EARLIER);

		$draft = $this->plans->planOf(caseId: self::CASE_ID);
		self::assertSame(['Wethouder Ruimte', 'Afdeling Vergunningen'], $this->plans->custodianNames(plan: $draft));
		self::assertSame(['files', 'microsoft365'], $draft['systems']);
		self::assertSame('Stationsweg', $draft['terms']);
		self::assertArrayNotHasKey('periodFrom', $draft);
		self::assertNull($this->plans->recorded(caseId: self::CASE_ID), 'the copy is a draft the handler completes and records');

		$copied = array_values(array_filter($this->register->all('wooRequestConfiguration'), static fn (array $row): bool => $row['case'] === self::CASE_ID));
		self::assertSame(self::EARLIER, $copied[0]['copiedFrom']);
		self::assertSame([], (new RealSchemaValidator())->errors(slug: 'wooSearchPlan', payload: $draft));
	}//end testANewRequestCopiesTheLastConfigurationWithoutThePeriod()

	public function testEveryPresentKeyIsCopied(): void {
		$this->register->seed('wooRequestConfiguration', 'conf-earlier', [
			'case' => self::EARLIER,
			'custodians' => [['name' => 'A']],
			'systems' => ['files'],
			'terms' => 't',
			'triageRules' => ['a key another change adds'],
			'copiedFrom' => 'older-case',
		]);

		$copy = $this->plans->startFrom(caseId: self::CASE_ID, from: self::EARLIER);

		self::assertSame(['a key another change adds'], $copy['triageRules']);
		self::assertSame(self::EARLIER, $copy['copiedFrom']);
		self::assertNull($this->plans->startFrom(caseId: self::CASE_ID, from: 'no-such-case'));
	}//end testEveryPresentKeyIsCopied()

	/**
	 * REQ-WRC-005 "Start from a named template".
	 *
	 * @return void
	 */
	public function testANewRequestStartsFromANamedTemplate(): void {
		$this->named[self::TEMPLATE] = [
			'uuid' => self::TEMPLATE,
			'name' => 'Ruimtelijke verzoeken',
			'values' => ['custodians' => [['name' => 'Wethouder Ruimte']], 'systems' => ['files'], 'terms' => 'Stationsweg', 'case' => 'not-this-one'],
		];

		$configuration = $this->plans->startFrom(caseId: self::CASE_ID, from: self::TEMPLATE);

		self::assertSame(['wooRequestConfiguration'], $this->askedSchemas, 'only a template of the configuration schema is asked for');
		self::assertSame(self::TEMPLATE, $configuration['copiedFrom']);
		self::assertSame(self::CASE_ID, $configuration['case'], 'a case named inside the template is not copied');
		$draft = $this->plans->planOf(caseId: self::CASE_ID);
		self::assertSame(['Wethouder Ruimte'], $this->plans->custodianNames(plan: $draft));
		self::assertSame(['files'], $draft['systems']);
		self::assertSame('Stationsweg', $draft['terms']);
		self::assertArrayNotHasKey('periodFrom', $draft);
		self::assertNull($this->plans->recorded(caseId: self::CASE_ID), 'the copy is a draft the handler completes and records');

		$real = new RealSchemaValidator();
		self::assertSame([], $real->errors(slug: 'wooRequestConfiguration', payload: $this->register->all('wooRequestConfiguration')[0]));
		self::assertSame([], $real->errors(slug: 'wooSearchPlan', payload: $draft));
	}//end testANewRequestStartsFromANamedTemplate()

	/**
	 * REQ-WRC-005 "A plan filled from a template names it".
	 *
	 * @return void
	 */
	public function testAPlanFilledFromATemplateNamesItAndKeepsItsOtherKeys(): void {
		$this->named[self::TEMPLATE] = [
			'uuid' => self::TEMPLATE,
			'name' => 'Ruimtelijke verzoeken',
			'values' => ['terms' => 'the template term', 'triageRules' => ['a key another change adds']],
		];

		$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen', startedFrom: self::TEMPLATE);

		$configuration = $this->register->all('wooRequestConfiguration')[0];
		self::assertSame(self::TEMPLATE, $configuration['copiedFrom']);
		self::assertSame(['a key another change adds'], $configuration['triageRules'], 'every key present is copied');
		self::assertSame('Stationsweg', $configuration['terms'], 'what the handler recorded wins over the template');
	}//end testAPlanFilledFromATemplateNamesItAndKeepsItsOtherKeys()

	public function testAPlanNamingATemplateTheCallerCannotUseIsRefused(): void {
		try {
			$this->plans->record(caseId: self::CASE_ID, input: $this->input(), userId: 'pjansen', startedFrom: self::TEMPLATE);
			self::fail('a plan cannot claim a template nobody can read');
		} catch (WooCorpusRefused $refused) {
			self::assertSame(400, $refused->getStatus());
			self::assertSame('template_not_found', $refused->getMessage());
		}

		self::assertSame([], $this->register->all('wooSearchPlan'), 'nothing is recorded under a false origin');
		self::assertSame([], $this->register->all('wooRequestConfiguration'));
	}//end testAPlanNamingATemplateTheCallerCannotUseIsRefused()
}//end class
