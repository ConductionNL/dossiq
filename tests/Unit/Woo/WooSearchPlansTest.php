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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooCorpusRefused;
use OCA\Dossiq\Woo\WooSearchPlans;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Woo\WooSearchPlans
 * @covers \OCA\Dossiq\Woo\WooCorpusRefused
 */
class WooSearchPlansTest extends TestCase {

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';
	private const EARLIER = '55555555-5555-4555-8555-555555555555';

	private InMemoryRegister $register;

	private WooSearchPlans $plans;

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
		$this->plans = new WooSearchPlans(settingsService: $settings);
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

	public function testADraftPlanCountsAsNone(): void {
		$this->register->seed('wooSearchPlan', 'draft-1', ['case' => self::CASE_ID, 'terms' => 'x']);

		self::assertNotNull($this->plans->find(caseId: self::CASE_ID));
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

		$draft = $this->plans->find(caseId: self::CASE_ID);
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
}//end class
