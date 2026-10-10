<?php

/**
 * BeschikkingNumberer unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\BeschikkingNumberer;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An in-memory stand-in for OpenRegister's SequenceService.
 *
 * Same contract: one counter per (register, schema, scope key), starting at 1,
 * never handing a value out twice.
 */
class InMemorySequences {

	/**
	 * Next value per scope key.
	 *
	 * @var array<string, int>
	 */
	public array $next = [];

	/**
	 * Reserve the next value for a scope.
	 *
	 * @param int    $registerId The register.
	 * @param int    $schemaId   The schema.
	 * @param string $scopeKey   The scope key.
	 *
	 * @return int The reserved value.
	 */
	public function reserveNext(int $registerId, int $schemaId, string $scopeKey): int {
		$key = $registerId.'/'.$schemaId.'/'.$scopeKey;
		$value = ($this->next[$key] ?? 1);
		$this->next[$key] = ($value + 1);

		return $value;
	}//end reserveNext()
}//end class

/**
 * Unit tests for BeschikkingNumberer.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\BeschikkingNumberer
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class BeschikkingNumbererTest extends TestCase {

	/**
	 * The counter double.
	 *
	 * @var InMemorySequences
	 */
	private InMemorySequences $sequences;

	/**
	 * Organisation per case id.
	 *
	 * @var array<string, string|null>
	 */
	private array $organisations = [];

	/**
	 * Build a numberer over the given container answer.
	 *
	 * @param object|null $sequences What the container returns, or null to throw.
	 *
	 * @return BeschikkingNumberer
	 */
	private function numberer(?object $sequences): BeschikkingNumberer {
		$cases = $this->createMock(CaseStatusStore::class);
		$cases->method('loadCase')->willReturnCallback(
			function (string $caseId): ?array {
				if (array_key_exists($caseId, $this->organisations) === false) {
					return null;
				}

				return ['id' => $caseId, '@self' => ['organisation' => $this->organisations[$caseId]]];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($sequences === null) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		} else {
			$container->method('get')->willReturn($sequences);
		}

		return new BeschikkingNumberer($cases, $container, $this->createMock(LoggerInterface::class));
	}//end numberer()

	/**
	 * Set up the counter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sequences = new InMemorySequences();
		$this->organisations = [
			'case-a1' => 'org-zuiddrecht',
			'case-a2' => 'org-zuiddrecht',
			'case-b1' => 'org-noorddijk',
		];
	}//end setUp()

	/**
	 * Numbers run per organisation: the neighbour's decision takes nothing from this year.
	 *
	 * @return void
	 */
	public function testEachOrganisationHasItsOwnRunningNumber(): void {
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-a1', $moment));
		self::assertSame('B-2026-000001', $numberer->issue('case-b1', $moment));
		self::assertSame('B-2026-000002', $numberer->issue('case-a2', $moment));
		self::assertSame('B-2026-000003', $numberer->issue('case-a1', $moment), 'A second decision on one case still takes the next number.');
	}//end testEachOrganisationHasItsOwnRunningNumber()

	/**
	 * The count restarts on the first of January.
	 *
	 * @return void
	 */
	public function testTheNumberRestartsEachYear(): void {
		$numberer = $this->numberer(sequences: $this->sequences);

		self::assertSame('B-2026-000001', $numberer->issue('case-a1', new DateTimeImmutable('2026-12-31')));
		self::assertSame('B-2026-000002', $numberer->issue('case-a1', new DateTimeImmutable('2026-12-31')));
		self::assertSame('B-2027-000001', $numberer->issue('case-a1', new DateTimeImmutable('2027-01-01')));
	}//end testTheNumberRestartsEachYear()

	/**
	 * A case that names no organisation numbers in the instance-wide row, apart from any organisation.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnOrganisationNumbersInTheInstanceRow(): void {
		$this->organisations['case-none'] = null;
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		$numberer->issue('case-a1', $moment);
		self::assertSame('B-2026-000001', $numberer->issue('case-none', $moment));
		self::assertSame('B-2026-000002', $numberer->issue('case-unreadable', $moment));
		self::assertArrayHasKey('0/0/dq-beschikking||2026', $this->sequences->next);
	}//end testACaseWithoutAnOrganisationNumbersInTheInstanceRow()

	/**
	 * An organisation id too long for the column is hashed, never cut, and still fits.
	 *
	 * @return void
	 */
	public function testALongOrganisationIdStillFitsTheScopeColumn(): void {
		$this->organisations['case-long'] = str_repeat('x', 80);
		$this->organisations['case-long2'] = str_repeat('x', 79).'y';
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-long', $moment));
		self::assertSame('B-2026-000001', $numberer->issue('case-long2', $moment), 'Two ids with one prefix keep two counters.');
		foreach (array_keys($this->sequences->next) as $key) {
			self::assertLessThanOrEqual(64, strlen(substr($key, 4)));
		}
	}//end testALongOrganisationIdStillFitsTheScopeColumn()

	/**
	 * Without OpenRegister's counter there is no number, and so no beschikking.
	 *
	 * @return void
	 */
	public function testNoCounterRefusesWithAnIndeterminateStatus(): void {
		$numberer = $this->numberer(sequences: null);

		try {
			$numberer->issue('case-a1');
			self::fail('A beschikking without a number must not be composed.');
		} catch (RefusedException $refusal) {
			self::assertSame('beschikking-number-unavailable', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $refusal->getStatus());
		}
	}//end testNoCounterRefusesWithAnIndeterminateStatus()

	/**
	 * A counter that answers nothing usable refuses too, rather than printing B-2026-000000.
	 *
	 * @return void
	 */
	public function testACounterAnsweringZeroRefuses(): void {
		$zero = new class {
			/**
			 * Answer zero.
			 *
			 * @param int    $registerId The register.
			 * @param int    $schemaId   The schema.
			 * @param string $scopeKey   The scope key.
			 *
			 * @return int Zero.
			 */
			public function reserveNext(int $registerId, int $schemaId, string $scopeKey): int {
				return 0;
			}//end reserveNext()
		};

		$this->expectException(RefusedException::class);
		$this->numberer(sequences: $zero)->issue('case-a1');
	}//end testACounterAnsweringZeroRefuses()
}//end class
