<?php

/**
 * Unit tests for LinkInFlightContractDecisionsRepair::run().
 *
 * The case search is the trait's protected method, overridden in a subclass
 * so each case type answers its own rows or fails; the delegation service,
 * the property restorer and the output are doubles that record every call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\LinkInFlightContractDecisionsRepair;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Characterisation tests for the in-flight contract decision link.
 *
 * @covers \OCA\Dossiq\Repair\LinkInFlightContractDecisionsRepair
 * @uses \OCA\Dossiq\Repair\InFlightDecisionLinkRun
 */
class LinkInFlightContractDecisionsRepairTest extends TestCase {

	/**
	 * Every output line, as "level: text".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * The output double.
	 *
	 * @return IOutput The output.
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $text): void {
			$this->lines[] = 'info: '.$text;
		});
		$output->method('warning')->willReturnCallback(function (string $text): void {
			$this->lines[] = 'warning: '.$text;
		});

		return $output;
	}//end repairOutput()

	/**
	 * A repair whose case search answers per case type.
	 *
	 * @param array<string, mixed> $casesByType The rows (or exception) per case type slug.
	 * @param object|null          $objects     The object service, or null when OpenRegister is gone.
	 *
	 * @return LinkInFlightContractDecisionsRepair The repair.
	 */
	private function repair(array $casesByType, ?object $objects): LinkInFlightContractDecisionsRepair {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);

		$delegation = $this->createMock(ContractDecisionDelegationService::class);
		$delegation->method('raiseContractDecision')->willReturnCallback(
			static function (string $caseRef): string {
				if ($caseRef === 'case-down') {
					throw new RuntimeException('decidesk unavailable');
				}

				return 'decision-'.$caseRef;
			}
		);
		$properties = $this->createMock(JsonEncodedStringProperties::class);
		$properties->method('mergeForWrite')->willReturnCallback(
			static fn (array $stored, array $updates): array => array_merge($stored, $updates)
		);

		return new class($delegation, $settings, $properties, new NullLogger(), $casesByType) extends LinkInFlightContractDecisionsRepair {
			/**
			 * @param array<string, mixed> $casesByType The answers.
			 */
			public function __construct(
				ContractDecisionDelegationService $delegation,
				SettingsService $settings,
				JsonEncodedStringProperties $properties,
				NullLogger $logger,
				private array $casesByType,
			) {
				parent::__construct($delegation, $settings, $properties, $logger);
			}

			/**
			 * @param object               $objectService The object service.
			 * @param int|string           $register      The register.
			 * @param int|string           $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			protected function searchObjectsAsArrays(object $objectService, int|string $register, int|string $schema, array $filters=[]): array {
				$objectService->calls[] = 'search '.$register.'/'.$schema.' '.json_encode($filters);
				$answer = ($this->casesByType[$filters['caseTypeSlug']] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};
	}//end repair()

	/**
	 * The object service double, run as the system.
	 *
	 * @return object The double.
	 */
	private function objects(): object {
		return new class {
			/**
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed What it answers.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->calls[] = 'system';
				return $operation();
			}

			/**
			 * @param array<string, mixed> $object The row.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function saveObject(array $object, string $register='', string $schema='', ?string $uuid=null): array {
				$this->calls[] = 'save '.$uuid.' '.$object['decisionRef'];
				return $object;
			}
		};
	}//end objects()

	/**
	 * Every case type is walked as the system, each case counted, and a failing list skipped.
	 *
	 * @return void
	 */
	public function testEveryCaseTypeIsWalkedAndCounted(): void {
		$objects = $this->objects();
		$this->repair(
			[
				'leverancier-contractverlenging-verzoek' => [
					['uuid' => 'case-1', 'title' => 'Verlenging'],
					['id' => 'case-2', 'besluitRef' => 'b-1'],
					['uuid' => ''],
				],
				'besluitvorming-college' => new RuntimeException('store down'),
				'besluitvorming-raad' => [
					['uuid' => 'case-3', 'status' => 'closed'],
					['uuid' => 'case-down'],
				],
				'besluitvorming-mandaat' => [['uuid' => 'case-4', 'decisionRef' => 'd-9']],
			],
			$objects
		)->run($this->repairOutput());

		self::assertSame(
			[
				'system',
				'search dossiq/case {"caseTypeSlug":"leverancier-contractverlenging-verzoek","_limit":500}',
				'save case-1 decision-case-1',
				'search dossiq/case {"caseTypeSlug":"besluitvorming-college","_limit":500}',
				'search dossiq/case {"caseTypeSlug":"besluitvorming-raad","_limit":500}',
				'search dossiq/case {"caseTypeSlug":"besluitvorming-mandaat","_limit":500}',
			],
			$objects->calls
		);
		self::assertSame(
			[
				'info: Linking in-flight contract/besluitvorming cases to decidesk Decisions...',
				'info: Linked case case-1 → decidesk Decision decision-case-1',
				'warning: Could not list cases for type besluitvorming-college: store down',
				'warning: Could not link case case-down: decidesk unavailable — skipping.',
				'info: Contract decision link complete: 1 linked, 3 skipped (already decided/historical), 1 errors (leaf unavailable).',
			],
			$this->lines
		);
	}//end testEveryCaseTypeIsWalkedAndCounted()

	/**
	 * Without OpenRegister the repair says so and stops.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsLinked(): void {
		$this->repair([], null)->run($this->repairOutput());

		self::assertSame(
			[
				'info: Linking in-flight contract/besluitvorming cases to decidesk Decisions...',
				'warning: OpenRegister unavailable — skipping in-flight contract decision link.',
			],
			$this->lines
		);
	}//end testWithoutOpenRegisterNothingIsLinked()
}//end class
