<?php

/**
 * Unit tests for the Woo stopping rule.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDocumentReviews;
use OCA\Dossiq\Woo\WooStoppingRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Declaring the stopping rule before review, and only then.
 *
 * @covers \OCA\Dossiq\Woo\WooStoppingRules
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 */
class WooStoppingRuleTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Two unmarked documents on case X.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'wooDocumentReview', uuid: 'r-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'unmarked']);
		$this->store->seed(schema: 'wooDocumentReview', uuid: 'r-2', row: ['case' => 'case-x', 'documentRef' => 'doc-2', 'relevance' => 'unmarked']);
	}//end setUp()

	/**
	 * The service on the store.
	 *
	 * @return WooStoppingRules The service.
	 */
	private function rules(): WooStoppingRules {
		$config = ['register' => 'dossiq', 'woo_review_schema' => 'wooDocumentReview', 'woo_stopping_rule_schema' => 'wooStoppingRule'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);

		return new WooStoppingRules(settingsService: $settings, reviews: new WooDocumentReviews(settingsService: $settings, logger: $logger), logger: $logger);
	}//end rules()

	/**
	 * The scenario: with nothing marked, 0.80 at 0.95 is stored with the handler and the time; declaring again replaces it.
	 *
	 * @return void
	 */
	public function testARuleIsDeclaredBeforeReview(): void {
		$rules = $this->rules();
		$this->assertNull($rules->forCase(caseId: 'case-x'));

		$rule = $rules->declare(caseId: 'case-x', targetRecall: 0.8, confidence: 0.95, userId: 'handler-h');

		$this->assertSame(0.8, $rule['targetRecall']);
		$this->assertSame(0.95, $rule['confidence']);
		$this->assertSame('handler-h', $rule['declaredBy']);
		$this->assertNotEmpty($rule['declaredAt']);

		$rules->declare(caseId: 'case-x', targetRecall: 0.9, confidence: 0.99, userId: 'handler-h');
		$this->assertCount(1, $this->store->all('wooStoppingRule'));
		$this->assertSame(0.9, $rules->forCase(caseId: 'case-x')['targetRecall']);
	}//end testARuleIsDeclaredBeforeReview()

	/**
	 * The scenario: once one document is marked in scope, lowering the target is refused and the rule stays 0.80.
	 *
	 * @return void
	 */
	public function testARuleCannotChangeOnceReviewStarted(): void {
		$rules = $this->rules();
		$rules->declare(caseId: 'case-x', targetRecall: 0.8, confidence: 0.95, userId: 'handler-h');
		$this->store->rows['wooDocumentReview']['r-1']['relevance'] = 'in-scope';

		$this->assertTrue($rules->reviewStarted(caseId: 'case-x'));
		try {
			$rules->declare(caseId: 'case-x', targetRecall: 0.7, confidence: 0.95, userId: 'handler-h');
			$this->fail('The stopping rule changed after review started.');
		} catch (RefusedException $e) {
			$this->assertSame('woo-stopping-rule-locked', $e->getRule());
			$this->assertSame(409, $e->getStatus());
		}

		$this->assertSame(0.8, $rules->forCase(caseId: 'case-x')['targetRecall']);
	}//end testARuleCannotChangeOnceReviewStarted()

	/**
	 * A target outside 0.5 to 0.99 and a confidence outside 0.90, 0.95, 0.99 are refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnOutOfRangeTargetIsRefused(): void {
		$rules = $this->rules();
		foreach ([[0.4, 0.95], [1.0, 0.95], [0.8, 0.8]] as [$target, $confidence]) {
			try {
				$rules->declare(caseId: 'case-x', targetRecall: $target, confidence: $confidence, userId: 'handler-h');
				$this->fail('Accepted '.$target.' at '.$confidence);
			} catch (RefusedException $e) {
				$this->assertSame('woo-stopping-rule-out-of-range', $e->getRule());
				$this->assertSame(422, $e->getStatus());
			}
		}

		$this->assertSame([], $this->store->all('wooStoppingRule'));
	}//end testAnOutOfRangeTargetIsRefused()
}//end class
