<?php

/**
 * Which published caseModel the case-plan projection finds, and when it finds none.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\CasePlanProjectionService
 * @uses \OCA\Dossiq\Service\SettingsService
 */
final class CasePlanModelLookupTest extends TestCase {

	/**
	 * The queries the store was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Error lines logged, with their context.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $errors = [];

	/**
	 * Project a cmmn case over a store answering the given hits.
	 *
	 * @param mixed                 $hits    What searchObjects answers, or a Throwable to throw.
	 * @param array<string, string> $config  Config values.
	 * @param bool                  $store   Whether there is a store.
	 * @param string                $typeId  The case type id.
	 *
	 * @return array<string, mixed> The projection answer.
	 */
	private function project(mixed $hits, array $config, bool $store=true, string $typeId='ct-1'): array {
		$test = $this;
		$objects = new class($test, $hits) {
			/**
			 * @param CasePlanModelLookupTest $test The test.
			 * @param mixed                   $hits The hits.
			 */
			public function __construct(private readonly CasePlanModelLookupTest $test, private readonly mixed $hits) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function searchObjects(array $query): mixed {
				$this->test->asked(query: $query);
				if ($this->hits instanceof \Throwable) {
					throw $this->hits;
				}

				return $this->hits;
			}
		};
		$layer = new class {
			/**
			 * @param string               $objectUuid The anchoring object.
			 * @param int|null             $registerId Its register.
			 * @param int|null             $schemaId   Its schema.
			 * @param array<string, mixed> $definition The definition.
			 * @param string               $app        The acting app id.
			 *
			 * @return array<string, mixed>
			 */
			public function createPlanAsSystem(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, string $app): array {
				unset($objectUuid, $registerId, $schemaId, $definition, $app);
				return [];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($layer);
		$settings->method('getObjectService')->willReturn($store === true ? $objects : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->errors[] = [$message, $context];
			}
		);

		$projection = new CasePlanProjectionService($settings, $logger);

		return $projection->projectAtCaseStart(caseId: 'case-1', caseType: ['id' => $typeId, 'handlingModel' => 'cmmn']);
	}//end project()

	/**
	 * Record a query.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return void
	 */
	public function asked(array $query): void {
		$this->queries[] = $query;
	}//end asked()

	/**
	 * Without a case type, a store, a register or a model schema nothing is searched.
	 *
	 * @return void
	 */
	public function testNothingIsSearchedWithoutAScope(): void {
		$config = ['register' => '7', 'case_model_schema' => '13', 'case_schema' => '11'];
		$none = ['projected' => false, 'reason' => 'no_published_case_model'];

		$this->assertSame($none, $this->project(hits: [], config: $config, typeId: ''));
		$this->assertSame($none, $this->project(hits: [], config: $config, store: false));
		$this->assertSame($none, $this->project(hits: [], config: ['case_model_schema' => '13']));
		$this->assertSame($none, $this->project(hits: [], config: ['register' => '7']));
		$this->assertSame([], $this->queries);
	}//end testNothingIsSearchedWithoutAScope()

	/**
	 * The search asks for the published model of the case type; no hit or a failed search finds none.
	 *
	 * @return void
	 */
	public function testTheSearchAsksForThePublishedModelAndAFailureFindsNone(): void {
		$config = ['register' => '7', 'case_model_schema' => '13', 'case_schema' => '11'];
		$none = ['projected' => false, 'reason' => 'no_published_case_model'];

		$this->assertSame($none, $this->project(hits: [], config: $config));
		$this->assertSame(
			[['@self' => ['register' => 7, 'schema' => 13], 'caseType' => 'ct-1', 'lifecycleStatus' => 'published']],
			$this->queries
		);

		$this->assertSame($none, $this->project(hits: new \RuntimeException('store down'), config: $config));
		$this->assertSame(
			[['CasePlanProjectionService: caseModel lookup failed', ['caseType' => 'ct-1', 'exception' => 'store down']]],
			$this->errors
		);
	}//end testTheSearchAsksForThePublishedModelAndAFailureFindsNone()

	/**
	 * A model whose planItems come back as JSON text is decoded before it is projected.
	 *
	 * @return void
	 */
	public function testAModelWithTextPlanItemsIsDecodedAndProjected(): void {
		$config = ['register' => '7', 'case_model_schema' => '13', 'case_schema' => '11'];
		$model = ['planItems' => '[{"id":"intake","type":"stage","name":"Intake"}]'];

		$this->assertSame(['projected' => true, 'reason' => 'projected'], $this->project(hits: [$model], config: $config));

		$this->assertSame(
			['projected' => false, 'reason' => 'caseModel.planItems must be a list of plan items.'],
			$this->project(hits: [['planItems' => 5]], config: $config)
		);
	}//end testAModelWithTextPlanItemsIsDecodedAndProjected()
}//end class
