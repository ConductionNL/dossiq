<?php

/**
 * Characterisation tests for CapacityGuard::evaluate and ::countIn: the exact
 * verdict and details for each outcome, every store answer countIn reads or
 * refuses, and which id a case is known by.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CapacityGuardGoldenTest extends TestCase {

	/**
	 * What findAll was asked, per call.
	 *
	 * @var array<int, array>
	 */
	private array $asked = [];

	/**
	 * A guard over a store that answers $answer (or throws it).
	 *
	 * @param mixed  $answer   What findAll answers; a Throwable is thrown.
	 * @param array  $config   The register and case schema configured.
	 * @param bool   $hasStore Whether there is an object service.
	 * @param int    $capacity The status's capacity.
	 *
	 * @return CapacityGuard The guard.
	 */
	private function guard(mixed $answer, array $config = ['register' => 'reg', 'case_schema' => 'case'], bool $hasStore = true, int $capacity = 2): CapacityGuard {
		$statuses = $this->createMock(StatusTypeLookup::class);
		$statuses->method('rowFor')->willReturn(['capacity' => $capacity]);
		$statuses->method('nameFor')->willReturn('Intake');

		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('isFinalStatus')->willReturn(false);

		$asked = &$this->asked;
		$objectService = new class($answer, $asked) {
			public function __construct(private mixed $answer, private array &$asked) {
			}

			public function findAll(array $params = []): mixed {
				$this->asked[] = $params;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($hasStore === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (string)($config[$key] ?? '')
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $text . ' ' . implode('|', $parameters)
		);

		return new CapacityGuard($statuses, $caseTypes, $settings, $l10n);
	}//end guard()

	/**
	 * An object row that serialises to an array.
	 *
	 * @param mixed $data What it serialises to.
	 *
	 * @return object The row.
	 */
	private function serialisable(mixed $data): object {
		return new class($data) implements \JsonSerializable {
			public function __construct(private mixed $data) {
			}

			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end serialisable()

	public function testCountInAsksByStatusAndCountsEveryRowShape(): void {
		$guard = $this->guard(
			[
				'results' => [
					['id' => 'a'],
					['uuid' => ' b ', 'id' => 'x'],
					['@self' => ['id' => 'c']],
					['@self' => ['uuid' => 'me']],
					['id' => ''],
					'not a row',
					$this->serialisable(['id' => 'd']),
					$this->serialisable('scalar'),
				],
			]
		);

		self::assertSame(7, $guard->countIn('st-1', 'me'));
		self::assertSame([['filters' => ['register' => 'reg', 'schema' => 'case', 'status' => 'st-1']]], $this->asked);
	}//end testCountInAsksByStatusAndCountsEveryRowShape()

	public function testCountInReadsABareListAndExcludesNothingByDefault(): void {
		self::assertSame(2, $this->guard([['id' => 'a'], ['id' => '']])->countIn('st-1'));
	}//end testCountInReadsABareListAndExcludesNothingByDefault()

	public function testCountInCountsAnExcludedCaseWithoutAnId(): void {
		self::assertSame(1, $this->guard([['title' => 'no id']])->countIn('st-1', ''));
	}//end testCountInCountsAnExcludedCaseWithoutAnId()

	public function testCountInAnswersNullWhenTheStoreCannot(): void {
		self::assertNull($this->guard(new \RuntimeException('down'))->countIn('st-1'));
		self::assertNull($this->guard('text')->countIn('st-1'));
		self::assertNull($this->guard(['results' => 'text'])->countIn('st-1'));
		self::assertNull($this->guard([], hasStore: false)->countIn('st-1'));
		self::assertNull($this->guard([], config: ['register' => '', 'case_schema' => 'case'])->countIn('st-1'));
		self::assertNull($this->guard([], config: ['register' => 'reg', 'case_schema' => ''])->countIn('st-1'));
		self::assertSame([['filters' => ['register' => 'reg', 'schema' => 'case', 'status' => 'st-1']]], array_slice($this->asked, 0, 1));
		self::assertCount(3, $this->asked);
	}//end testCountInAnswersNullWhenTheStoreCannot()

	public function testEvaluateAnswersEachVerdictWithItsDetails(): void {
		$below = $this->guard(['results' => [['id' => 'a']]])->evaluate(['toStatus' => ' st-1 '], ['id' => 'me'], 'u');
		self::assertTrue($below->passed);
		self::assertSame(['capacity' => 2, 'count' => 1], $below->details);

		$full = $this->guard(['results' => [['id' => 'a'], ['id' => 'b'], ['id' => 'me']]])->evaluate(['toStatus' => 'st-1'], ['@self' => ['id' => 'me']], 'u');
		self::assertFalse($full->passed);
		self::assertSame('Status %1$s is full: %2$s of %3$s cases. Intake|2|2', $full->failureMessage);
		self::assertSame(['statusType' => 'st-1', 'capacity' => 2, 'count' => 2], $full->details);

		$unreadable = $this->guard(new \RuntimeException('down'))->evaluate(['toStatus' => 'st-1'], [], 'u');
		self::assertTrue($unreadable->passed);
		self::assertSame(['unreadable' => true], $unreadable->details);

		$noLimit = $this->guard([], capacity: 0)->evaluate(['toStatus' => 'st-1'], [], 'u');
		self::assertTrue($noLimit->passed);
		self::assertSame([], $noLimit->details);
	}//end testEvaluateAnswersEachVerdictWithItsDetails()
}//end class
