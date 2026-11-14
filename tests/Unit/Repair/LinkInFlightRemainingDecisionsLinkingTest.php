<?php

/**
 * In-flight objection, advice and consultation records are linked to a decidesk Decision once.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\LinkInFlightRemainingDecisionsRepair;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\LinkInFlightRemainingDecisionsRepair
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class LinkInFlightRemainingDecisionsLinkingTest extends TestCase {

	/**
	 * What the step reported, prefixed with the level.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * An output that records every line.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = 'info: ' . $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said[] = 'warning: ' . $line;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * A store answering each schema slug with fixture rows, recording every save.
	 *
	 * @param array<string, array|\Throwable> $rowsBySchema Rows per schema slug, or a throwable.
	 *
	 * @return object The store.
	 */
	private function store(array $rowsBySchema): object {
		return new class($rowsBySchema) {
			/**
			 * Every saveObject() call, as named arguments.
			 *
			 * @var list<array>
			 */
			public array $saved = [];

			/**
			 * Whether the work ran as the system.
			 *
			 * @var bool
			 */
			public bool $asSystem = false;

			/**
			 * The store.
			 *
			 * @param array $rowsBySchema Rows per schema slug.
			 */
			public function __construct(private readonly array $rowsBySchema) {
			}//end __construct()

			/**
			 * Run work as the system.
			 *
			 * @param callable $work The work.
			 *
			 * @return mixed What the work returns.
			 */
			public function runAsSystem(callable $work): mixed {
				$this->asSystem = true;
				return $work();
			}//end runAsSystem()

			/**
			 * The rows of one schema.
			 *
			 * @param string $registerSlug The register.
			 * @param string $schemaSlug   The schema.
			 * @param array  $filters      The filters.
			 *
			 * @return array The rows.
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[]): array {
				$rows = ($this->rowsBySchema[$schemaSlug] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}//end searchObjectsBySlug()

			/**
			 * Record a save.
			 *
			 * @param array  $object   The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 * @param string $uuid     The uuid.
			 *
			 * @return array The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				$this->saved[] = compact('object', 'register', 'schema', 'uuid');
				return $object;
			}//end saveObject()
		};
	}//end store()

	/**
	 * Properties that merge the update into the stored object.
	 *
	 * @return JsonEncodedStringProperties&MockObject The properties.
	 */
	private function mergingProperties(): JsonEncodedStringProperties&MockObject {
		$properties = $this->createMock(JsonEncodedStringProperties::class);
		$properties->method('mergeForWrite')->willReturnCallback(
			static fn (array $stored, array $updates, string $schemaSlug): array => array_merge($stored, $updates, ['_schema' => $schemaSlug])
		);

		return $properties;
	}//end mergingProperties()

	/**
	 * The step over the given collaborators, every surface configured.
	 *
	 * @param object|null                      $store     The store.
	 * @param BezwaarDecisionDelegationService $objection The objection delegation.
	 * @param AdviceDelegationService          $advice    The advice delegation.
	 * @param LoggerInterface|null             $logger    The logger.
	 *
	 * @return LinkInFlightRemainingDecisionsRepair The step.
	 */
	private function step(
		?object $store,
		BezwaarDecisionDelegationService $objection,
		AdviceDelegationService $advice,
		?LoggerInterface $logger=null,
	): LinkInFlightRemainingDecisionsRepair {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturn('12');

		return new LinkInFlightRemainingDecisionsRepair(
			$objection,
			$advice,
			$settings,
			$this->mergingProperties(),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end step()

	/**
	 * Without OpenRegister nothing is raised and the operator is warned.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsLinked(): void {
		$objection = $this->createMock(BezwaarDecisionDelegationService::class);
		$objection->expects($this->never())->method('raiseBezwaarDecision');

		$this->step(null, $objection, $this->createMock(AdviceDelegationService::class))->run($this->recordingOutput());

		self::assertSame(
			[
				'info: Linking in-flight bezwaar/advies/consultatie objects to decidesk Decisions...',
				'warning: OpenRegister unavailable — skipping in-flight remaining-decision link.',
			],
			$this->said
		);
	}//end testWithoutOpenRegisterNothingIsLinked()

	/**
	 * Each open record raises its decision with the record's own fields and stores the reference.
	 *
	 * @return void
	 */
	public function testEachOpenRecordRaisesItsDecisionAndStoresTheReference(): void {
		$store = $this->store([
			'bezwaarDecision' => [[
				'uuid' => 'bd-1',
				'objectionProceeding' => 'op-9',
				'title' => 'Bezwaar Jansen',
				'dispositionType' => 'gegrond',
				'reasoning' => 'r',
				'legalBasis' => 'Awb 7:11',
			]],
			'adviesAanvraag' => [['id' => 'aa-1', 'caseRef' => 'case-1', 'question' => 'Mag dit?']],
			'consultation' => [['uuid' => 'co-1', 'parentCase' => 'case-2', 'consultationNumber' => 'C-7', 'questionFormulation' => 'Wat vindt u?']],
		]);
		$objection = $this->createMock(BezwaarDecisionDelegationService::class);
		$objection->expects($this->once())->method('raiseBezwaarDecision')->with(
			'op-9',
			[
				'subjectSchema' => 'bezwaarDecision',
				'subjectId' => 'bd-1',
				'subjectLabel' => 'Bezwaar Jansen',
				'dispositionType' => 'gegrond',
				'reasoning' => 'r',
				'legalBasis' => 'Awb 7:11',
			]
		)->willReturn('dec-1');
		$advice = $this->createMock(AdviceDelegationService::class);
		$advice->expects($this->exactly(2))->method('raiseAdviceDecision')->willReturnCallback(
			static function (string $subjectSchema, string $subjectId, array $payload): string {
				$expected = [
					'adviesAanvraag' => ['aa-1', ['externalReference' => 'case-1', 'subjectLabel' => 'Mag dit?', 'question' => 'Mag dit?']],
					'consultation' => ['co-1', ['externalReference' => 'case-2', 'subjectLabel' => 'C-7', 'question' => 'Wat vindt u?']],
				];
				self::assertSame($expected[$subjectSchema], [$subjectId, $payload]);

				return 'dec-' . $subjectId;
			}
		);

		$this->step($store, $objection, $advice)->run($this->recordingOutput());

		self::assertTrue($store->asSystem);
		self::assertSame(['bd-1', 'aa-1', 'co-1'], array_column($store->saved, 'uuid'));
		self::assertSame(['dossiq', 'dossiq', 'dossiq'], array_column($store->saved, 'register'));
		self::assertSame(['bezwaarDecision', 'adviesAanvraag', 'consultation'], array_column($store->saved, 'schema'));
		self::assertSame('dec-1', $store->saved[0]['object']['decisionRef']);
		self::assertSame('Bezwaar Jansen', $store->saved[0]['object']['title'], 'the stored record is merged, not replaced');
		self::assertSame('bezwaarDecision', $store->saved[0]['object']['_schema']);
		self::assertSame('dec-co-1', $store->saved[2]['object']['decisionRef']);
		self::assertContains('info: Linked bezwaarDecision bd-1 → decidesk Decision dec-1', $this->said);
		self::assertSame(
			'info: Remaining-decision link complete: 3 linked, 0 skipped (already decided/historical), 0 errors (leaf unavailable).',
			end($this->said)
		);
	}//end testEachOpenRecordRaisesItsDecisionAndStoresTheReference()

	/**
	 * Linked, decided and terminal records are kept; a record without an id is ignored.
	 *
	 * @return void
	 */
	public function testLinkedDecidedAndClosedRecordsAreLeftAlone(): void {
		$store = $this->store([
			'bezwaarDecision' => [
				['uuid' => 'a', 'decisionRef' => 'dec-old'],
				['uuid' => 'b', 'besluitRef' => 'bes-old'],
				['uuid' => 'c', 'status' => 'withdrawn'],
				['title' => 'no id'],
			],
			'adviesAanvraag' => [['uuid' => 'd', 'status' => 'archived']],
		]);
		$objection = $this->createMock(BezwaarDecisionDelegationService::class);
		$objection->expects($this->never())->method('raiseBezwaarDecision');
		$advice = $this->createMock(AdviceDelegationService::class);
		$advice->expects($this->never())->method('raiseAdviceDecision');

		$this->step($store, $objection, $advice)->run($this->recordingOutput());

		self::assertSame([], $store->saved);
		self::assertSame(
			'info: Remaining-decision link complete: 0 linked, 4 skipped (already decided/historical), 0 errors (leaf unavailable).',
			end($this->said)
		);
	}//end testLinkedDecidedAndClosedRecordsAreLeftAlone()

	/**
	 * An unavailable decidesk leaf is an error for that record only, never a failed migration.
	 *
	 * @return void
	 */
	public function testAnUnavailableLeafCountsAsAnErrorAndMovesOn(): void {
		$store = $this->store([
			'consultation' => [['uuid' => 'x'], ['uuid' => 'y']],
		]);
		$advice = $this->createMock(AdviceDelegationService::class);
		$advice->method('raiseAdviceDecision')->willReturnCallback(
			static function (string $subjectSchema, string $subjectId): string {
				if ($subjectId === 'x') {
					throw new RuntimeException('decidesk is not installed');
				}

				return 'dec-y';
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'LinkInFlightRemainingDecisionsRepair: could not link object',
			['schema' => 'consultation', 'uuid' => 'x', 'error' => 'decidesk is not installed']
		);

		$this->step($store, $this->createMock(BezwaarDecisionDelegationService::class), $advice, $logger)
			->run($this->recordingOutput());

		self::assertSame(['y'], array_column($store->saved, 'uuid'));
		self::assertContains('warning: Could not link consultation x: decidesk is not installed — skipping.', $this->said);
		self::assertSame(
			'info: Remaining-decision link complete: 1 linked, 0 skipped (already decided/historical), 1 errors (leaf unavailable).',
			end($this->said)
		);
	}//end testAnUnavailableLeafCountsAsAnErrorAndMovesOn()

	/**
	 * A schema that cannot be listed is reported and the other surfaces still run.
	 *
	 * @return void
	 */
	public function testAnUnlistableSchemaIsReportedAndTheRestStillRun(): void {
		$store = $this->store([
			'bezwaarDecision' => new RuntimeException('index offline'),
			'adviesAanvraag' => [['uuid' => 'aa']],
		]);
		$advice = $this->createMock(AdviceDelegationService::class);
		$advice->method('raiseAdviceDecision')->willReturn('dec-aa');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'LinkInFlightRemainingDecisionsRepair: list failed',
			['schema' => 'bezwaarDecision', 'error' => 'index offline']
		);

		$this->step($store, $this->createMock(BezwaarDecisionDelegationService::class), $advice, $logger)
			->run($this->recordingOutput());

		self::assertContains('warning: Could not list objects for schema bezwaarDecision: index offline', $this->said);
		self::assertSame(['aa'], array_column($store->saved, 'uuid'));
	}//end testAnUnlistableSchemaIsReportedAndTheRestStillRun()
}//end class
