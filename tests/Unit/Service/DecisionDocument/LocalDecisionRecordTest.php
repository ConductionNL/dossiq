<?php

/**
 * Unit tests for LocalDecisionRecord: the decision record of a case, composed and saved.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\DecisionDocument\LocalDecisionRecord;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WooDecisionSubject;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The decision record of a case, through LocalDecisionRecord.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class LocalDecisionRecordTest extends TestCase {

	private const CONFIG = ['register' => 'dossiq', 'decision_schema' => 'decision', 'woo_assessment_schema' => 'wooAssessment'];

	/**
	 * An object service that answers two assessments and keeps every save.
	 *
	 * @return object The object service.
	 */
	private function objectService(): object {
		return new class {
			/**
			 * The saves, each as [object, options].
			 *
			 * @var array<int, array{0: array<string, mixed>, 1: ObjectSaveOptions}>
			 */
			public array $saves = [];

			/**
			 * The case's assessments.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 * @param bool                 $_rbac    Ignored.
			 * @param bool                 $_mt      Ignored.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_mt = true): array {
				return [
					['documentRef' => 'doc-1', 'classification' => 'openbaar', 'weigeringsgronden' => []],
					['documentRef' => 'doc-2', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.5']],
				];
			}

			/**
			 * Keep the save and answer a saved decision.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options Where it is saved.
			 *
			 * @return object The saved decision.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): object {
				$this->saves[] = [$object, $options];
				return new class {
					/**
					 * The saved decision's uuid.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'decision-1';
					}
				};
			}
		};
	}//end objectService()

	/**
	 * A record over the object service with the given configuration.
	 *
	 * @param object|null          $objectService The object service, or none.
	 * @param array<string,string> $config        The configuration values.
	 * @param LoggerInterface|null $logger        The logger.
	 *
	 * @return LocalDecisionRecord The record.
	 */
	private function record(?object $objectService, array $config = self::CONFIG, ?LoggerInterface $logger = null): LocalDecisionRecord {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new LocalDecisionRecord(settingsService: $settings, logger: ($logger ?? new NullLogger()));
	}//end record()

	/**
	 * Compose answers the register and the case's assessment tally.
	 *
	 * @return void
	 */
	public function testComposeAnswersTheRegisterAndTheTally(): void {
		$composed = $this->record(objectService: $this->objectService())->compose(caseId: 'case-1');

		$this->assertSame(expected: 'dossiq', actual: $composed['register']);
		$this->assertSame(expected: 2, actual: $composed['record']['assessmentCount']);
		$this->assertSame(expected: ['5.1.5'], actual: $composed['record']['weigeringsgronden']);
	}//end testComposeAnswersTheRegisterAndTheTally()

	/**
	 * Compose needs OpenRegister and a configured decision schema.
	 *
	 * @return void
	 */
	public function testComposeNeedsOpenRegisterAndTheSchema(): void {
		try {
			$this->record(objectService: null)->compose(caseId: 'case-1');
			$this->fail(message: 'Without OpenRegister nothing is composed.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'OpenRegister is not available', actual: $e->getMessage());
		}

		$this->expectExceptionMessage(message: 'Decision schema not configured');
		$this->record(objectService: $this->objectService(), config: ['register' => 'dossiq'])->compose(caseId: 'case-1');
	}//end testComposeNeedsOpenRegisterAndTheSchema()

	/**
	 * The besluit is saved under the decision schema, named by the label, with the override merged and no identity kept.
	 *
	 * @return void
	 */
	public function testTheBesluitIsSavedNamedByItsLabel(): void {
		$objectService = $this->objectService();
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains(string: 'decision decision-1'),
			$this->callback(callback: static fn (array $context): bool => $context['decisionRef'] === 'decidiq-7')
		);
		$subject = new WooDecisionSubject(
			caseId: 'case-1',
			register: 'dossiq',
			record: ['caseTitle' => 'Brug', 'summary' => ['openbaar' => 1], 'weigeringsgronden' => [], 'assessmentCount' => 1],
			label: 'Besluit op het Woo-verzoek "Brug"'
		);

		$decisionId = $this->record(objectService: $objectService, logger: $logger)->record(
			subject: $subject,
			userId: 'j.dejong',
			decisionData: ['id' => 'forged', 'decisionDate' => '2026-11-01'],
			decisionRef: 'decidiq-7'
		);

		$this->assertSame(expected: 'decision-1', actual: $decisionId);
		[$saved, $options] = $objectService->saves[0];
		$this->assertSame(expected: 'case-1', actual: $saved['case']);
		$this->assertSame(expected: WOODecisionService::DECISION_TYPE_ID, actual: $saved['decisionType']);
		$this->assertSame(expected: 'Besluit op het Woo-verzoek "Brug"', actual: $saved['description']);
		$this->assertSame(expected: '2026-11-01', actual: $saved['decisionDate']);
		$this->assertSame(expected: 'j.dejong', actual: $saved['decidedBy']);
		$this->assertSame(expected: WooRefusalGrounds::LIST_VERSION, actual: $saved['groundsListVersion']);
		$this->assertArrayNotHasKey(key: 'id', array: $saved);
		$this->assertSame(expected: 'decision', actual: $options->target->schema);
		$this->assertSame(expected: 'dossiq', actual: $options->target->register);
	}//end testTheBesluitIsSavedNamedByItsLabel()
}//end class
