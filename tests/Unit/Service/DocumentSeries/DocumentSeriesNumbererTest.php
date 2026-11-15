<?php

/**
 * DocumentSeriesNumberer unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DocumentSeries
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
 * @spec openspec/specs/numbered-document-series/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DocumentSeries;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An in-memory stand-in for OpenRegister's SequenceService: one counter per
 * (register, schema, scope key), starting at 1, never handing a value out twice.
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
 * Unit tests for DocumentSeriesNumberer.
 *
 * @covers \OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class DocumentSeriesNumbererTest extends TestCase {

	/**
	 * The counter double.
	 *
	 * @var InMemorySequences
	 */
	private InMemorySequences $sequences;

	/**
	 * Cases by id: organisation and case type.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cases = [];

	/**
	 * Case types by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $caseTypes = [];

	/**
	 * Build a numberer over the given container answer.
	 *
	 * @param object|null $sequences What the container returns, or null to throw.
	 *
	 * @return DocumentSeriesNumberer
	 */
	private function numberer(?object $sequences): DocumentSeriesNumberer {
		$cases = $this->createMock(CaseStatusStore::class);
		$cases->method('loadCase')->willReturnCallback(fn (string $caseId): ?array => ($this->cases[$caseId] ?? null));

		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->method('effectiveCaseType')->willReturnCallback(
			function (string $caseTypeId): array {
				if ($caseTypeId === 'ct-broken') {
					throw new RuntimeException('unreadable');
				}

				return ($this->caseTypes[$caseTypeId] ?? []);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($sequences === null) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		} else {
			$container->method('get')->willReturn($sequences);
		}

		return new DocumentSeriesNumberer($cases, $caseTypes, $container, $this->createMock(LoggerInterface::class));
	}//end numberer()

	/**
	 * Set up two organisations and a case type that configures its own prefix.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sequences = new InMemorySequences();
		$this->cases = [
			'case-a1' => ['caseType' => 'ct-plain', '@self' => ['organisation' => 'org-zuiddrecht']],
			'case-a2' => ['caseType' => 'ct-plain', '@self' => ['organisation' => 'org-zuiddrecht']],
			'case-b1' => ['caseType' => 'ct-plain', '@self' => ['organisation' => 'org-noorddijk']],
			'case-own' => ['caseType' => 'ct-own', '@self' => ['organisation' => 'org-zuiddrecht']],
			'case-broken' => ['caseType' => 'ct-broken', '@self' => ['organisation' => 'org-zuiddrecht']],
		];
		$this->caseTypes = [
			'ct-plain' => [],
			'ct-own' => [DocumentSeriesNumberer::CASE_TYPE_KEY => ['decision' => ['prefix' => 'BES']]],
		];
	}//end setUp()

	/**
	 * Numbers run per organisation: the neighbour's document takes nothing from this year.
	 *
	 * @return void
	 */
	public function testEachOrganisationHasItsOwnRunningNumber(): void {
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-a1', 'decision', 'B', $moment));
		self::assertSame('B-2026-000001', $numberer->issue('case-b1', 'decision', 'B', $moment));
		self::assertSame('B-2026-000002', $numberer->issue('case-a2', 'decision', 'B', $moment));
	}//end testEachOrganisationHasItsOwnRunningNumber()

	/**
	 * Two series of one organisation keep two counters.
	 *
	 * @return void
	 */
	public function testEachSeriesHasItsOwnCounter(): void {
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-a1', 'decision', 'B', $moment));
		self::assertSame('V-2026-000001', $numberer->issue('case-a1', 'permit', 'V', $moment));
		self::assertSame('B-2026-000002', $numberer->issue('case-a1', 'decision', 'B', $moment));
	}//end testEachSeriesHasItsOwnCounter()

	/**
	 * The case type configures the prefix; the counter is the series', not the prefix's.
	 *
	 * @return void
	 */
	public function testTheCaseTypeConfiguresThePrefix(): void {
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-a1', 'decision', 'B', $moment));
		self::assertSame('BES-2026-000002', $numberer->issue('case-own', 'decision', 'B', $moment));
		self::assertSame('B-2026-000003', $numberer->issue('case-broken', 'decision', 'B', $moment), 'An unreadable case type prints the default.');
	}//end testTheCaseTypeConfiguresThePrefix()

	/**
	 * The count restarts on the first of January.
	 *
	 * @return void
	 */
	public function testTheNumberRestartsEachYear(): void {
		$numberer = $this->numberer(sequences: $this->sequences);

		self::assertSame('B-2026-000001', $numberer->issue('case-a1', 'decision', 'B', new DateTimeImmutable('2026-12-31')));
		self::assertSame('B-2026-000002', $numberer->issue('case-a1', 'decision', 'B', new DateTimeImmutable('2026-12-31')));
		self::assertSame('B-2027-000001', $numberer->issue('case-a1', 'decision', 'B', new DateTimeImmutable('2027-01-01')));
	}//end testTheNumberRestartsEachYear()

	/**
	 * A case that cannot be read numbers in the instance-wide row with the default prefix.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseNumbersInTheInstanceRow(): void {
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-unreadable', 'decision', 'B', $moment));
		self::assertArrayHasKey('0/0/dq-series|decision||2026', $this->sequences->next);
	}//end testAnUnreadableCaseNumbersInTheInstanceRow()

	/**
	 * An organisation id too long for the column is hashed, never cut, and still fits.
	 *
	 * @return void
	 */
	public function testALongOrganisationIdStillFitsTheScopeColumn(): void {
		$this->cases['case-long'] = ['@self' => ['organisation' => str_repeat('x', 80)]];
		$this->cases['case-long2'] = ['@self' => ['organisation' => str_repeat('x', 79).'y']];
		$numberer = $this->numberer(sequences: $this->sequences);
		$moment = new DateTimeImmutable('2026-10-10');

		self::assertSame('B-2026-000001', $numberer->issue('case-long', 'decision', 'B', $moment));
		self::assertSame('B-2026-000001', $numberer->issue('case-long2', 'decision', 'B', $moment), 'Two ids with one prefix keep two counters.');
		foreach (array_keys($this->sequences->next) as $key) {
			self::assertLessThanOrEqual(64, strlen(substr($key, 4)));
		}
	}//end testALongOrganisationIdStillFitsTheScopeColumn()

	/**
	 * Without OpenRegister's counter there is no number.
	 *
	 * @return void
	 */
	public function testNoCounterRefusesWithAnIndeterminateStatus(): void {
		try {
			$this->numberer(sequences: null)->issue('case-a1', 'decision', 'B');
			self::fail('A document without a number must not be issued.');
		} catch (RefusedException $refusal) {
			self::assertSame('document-number-unavailable', $refusal->getRule());
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
		$this->numberer(sequences: $zero)->issue('case-a1', 'decision', 'B');
	}//end testACounterAnsweringZeroRefuses()
}//end class
