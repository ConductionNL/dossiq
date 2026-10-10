<?php

/**
 * Unit tests for the Woo stopping rule guard.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\ReviewListenerRegistrar;
use OCA\Dossiq\Listener\StoppingRuleGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\StoppingRules;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Direct OpenRegister writes to the stopping rule, through the OpenRegister event classes.
 *
 * @covers \OCA\Dossiq\Listener\StoppingRuleGuard
 * @covers \OCA\Dossiq\AppInfo\Registrar\ReviewListenerRegistrar
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 */
class StoppingRuleGuardTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One unmarked document on case X.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'unmarked']);
	}//end setUp()

	/**
	 * The guard on the store.
	 *
	 * @return StoppingRuleGuard The guard.
	 */
	private function guard(): StoppingRuleGuard {
		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview', 'stopping_rule_schema' => '88'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$rules = new StoppingRules(settingsService: $settings, reviews: new DocumentRelevance(settingsService: $settings, logger: $logger), logger: $logger);

		return new StoppingRuleGuard(settingsService: $settings, rules: $rules, logger: $logger);
	}//end guard()

	/**
	 * A stopping rule entity of case X.
	 *
	 * @param float $target The target recall.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function rule(float $target, string $schema = '88'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['case' => 'case-x', 'targetRecall' => $target, 'confidence' => 0.95]);
		$entity->setSchema($schema);
		$entity->setUuid('rule-1');

		return $entity;
	}//end rule()

	/**
	 * The scenario: with one document marked in scope, lowering the target to 0.70 or deleting the rule is refused.
	 *
	 * @return void
	 */
	public function testARuleCannotChangeOnceReviewStarted(): void {
		$this->store->rows['documentReview']['r-1']['relevance'] = 'in-scope';

		$update = new ObjectUpdatingEvent($this->rule(0.7), $this->rule(0.8));
		$this->guard()->handle(event: $update);
		$this->assertTrue($update->isPropagationStopped());
		$this->assertSame(StoppingRuleGuard::ERROR_CODE, $update->getErrors()['code']);

		$delete = new ObjectDeletingEvent($this->rule(0.8));
		$this->guard()->handle(event: $delete);
		$this->assertTrue($delete->isPropagationStopped());
	}//end testARuleCannotChangeOnceReviewStarted()

	/**
	 * Before review, and on another schema, the write passes.
	 *
	 * @return void
	 */
	public function testBeforeReviewAndOnOtherSchemasTheWritePasses(): void {
		$update = new ObjectUpdatingEvent($this->rule(0.7), $this->rule(0.8));
		$this->guard()->handle(event: $update);
		$this->assertFalse($update->isPropagationStopped());

		$this->store->rows['documentReview']['r-1']['relevance'] = 'out-of-scope';
		$other = new ObjectUpdatingEvent($this->rule(0.7, '12'), $this->rule(0.8, '12'));
		$this->guard()->handle(event: $other);
		$this->assertFalse($other->isPropagationStopped());
	}//end testBeforeReviewAndOnOtherSchemasTheWritePasses()

	/**
	 * The registrar binds the guard to the updating and deleting events.
	 *
	 * @return void
	 */
	public function testTheRegistrarBindsTheGuard(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$bound = [];
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$bound): void {
				$bound[] = [$event, $listener];
			}
		);

		(new ReviewListenerRegistrar())->register(context: $context);

		$this->assertContains([ObjectUpdatingEvent::class, StoppingRuleGuard::class], $bound);
		$this->assertContains([ObjectDeletingEvent::class, StoppingRuleGuard::class], $bound);
	}//end testTheRegistrarBindsTheGuard()
}//end class
