<?php

/**
 * Storing the stopping rule: no schema, a failing store, and a serialised answer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
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

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\StoppingRules;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Review\StoppingRules
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 */
class StoppingRulesStorageTest extends TestCase {

	/**
	 * The object store double.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A store without rules or reviews.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->store  = new class {
			public array $saves = [];
			public mixed $answer = null;
			public ?\Throwable $failure = null;

			public function searchObjectsBySlug(string $register, string $schema, array $filters, mixed ...$scope): array {
				return [];
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid): mixed {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return ($this->answer ?? $object);
			}
		};
	}//end setUp()

	/**
	 * The rules over the store, with or without the stopping rule schema.
	 *
	 * @param string $schema The configured stopping rule schema.
	 *
	 * @return StoppingRules
	 */
	private function rules(string $schema='stopping-rule'): StoppingRules {
		$config   = ['register' => 'woo', StoppingRules::SCHEMA_KEY => $schema, DocumentRelevance::SCHEMA_KEY => 'document-review'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default=''): string => ($config[$key] ?? $default));

		return new StoppingRules(settingsService: $settings, reviews: new DocumentRelevance(settingsService: $settings, logger: $this->logger), logger: $this->logger);
	}//end rules()

	/**
	 * Without the schema there is no rule, and declaring one is refused as indeterminate.
	 *
	 * @return void
	 */
	public function testWithoutTheSchemaNothingIsStored(): void {
		$rules = $this->rules(schema: '');
		$this->assertNull($rules->forCase(caseId: 'c-1'));

		try {
			$rules->declare(caseId: 'c-1', targetRecall: 0.8, confidence: 0.95, userId: 'u');
			$this->fail('no refusal');
		} catch (RefusedException $e) {
			$this->assertSame('review-stopping-rule-unavailable', $e->getRule());
			$this->assertSame('The stopping rule cannot be stored, so nothing was declared.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertNull($e->getPrevious());
		}

		$this->assertSame([], $this->store->saves);
	}//end testWithoutTheSchemaNothingIsStored()

	/**
	 * A failing store is logged and the declaration refused, with the failure as the cause.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedAndRefused(): void {
		$failure = new RuntimeException('register locked');
		$this->store->failure = $failure;
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: a Stopping rule could not be stored',
			['app' => 'dossiq', 'exception' => 'register locked']
		);

		try {
			$this->rules()->declare(caseId: 'c-1', targetRecall: 0.8, confidence: 0.95, userId: 'u');
			$this->fail('no refusal');
		} catch (RefusedException $e) {
			$this->assertSame('review-stopping-rule-unavailable', $e->getRule());
			$this->assertSame($failure, $e->getPrevious());
		}
	}//end testAFailingStoreIsLoggedAndRefused()

	/**
	 * A stored rule answered as an entity is returned serialised.
	 *
	 * @return void
	 */
	public function testAStoredEntityIsReturnedSerialised(): void {
		$this->store->answer = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'rule-1', 'targetRecall' => 0.8];
			}
		};

		$this->assertSame(['id' => 'rule-1', 'targetRecall' => 0.8], $this->rules()->declare(caseId: 'c-1', targetRecall: 0.8, confidence: 0.95, userId: 'u'));
		$this->assertNull($this->store->saves[0]['uuid'], 'no rule yet, so a new one');
		$this->assertSame('stopping-rule', $this->store->saves[0]['schema']);
		$this->assertSame('woo', $this->store->saves[0]['register']);
	}//end testAStoredEntityIsReturnedSerialised()
}//end class
