<?php

/**
 * DocumentSeriesNumberer edge-case unit tests.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge cases of DocumentSeriesNumberer: the scope key, the configured prefix,
 * and what is logged when a number or a case type cannot be read.
 *
 * @covers \OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class DocumentSeriesNumbererEdgeCasesTest extends TestCase {

	/**
	 * The case store double.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $cases;

	/**
	 * The case type resolver double.
	 *
	 * @var CaseTypeResolver&MockObject
	 */
	private CaseTypeResolver $caseTypes;

	/**
	 * The container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The counter double: records every scope key and answers a fixed value.
	 *
	 * @var object
	 */
	private object $sequences;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cases = $this->createMock(CaseStatusStore::class);
		$this->caseTypes = $this->createMock(CaseTypeResolver::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->sequences = new class {
			/**
			 * The calls received, as [registerId, schemaId, scopeKey].
			 *
			 * @var array<int, array{0: int, 1: int, 2: string}>
			 */
			public array $calls = [];

			/**
			 * The value answered.
			 *
			 * @var int|string
			 */
			public int|string $answer = 7;

			/**
			 * Reserve the next value.
			 *
			 * @param int    $registerId The register.
			 * @param int    $schemaId   The schema.
			 * @param string $scopeKey   The scope key.
			 *
			 * @return int|string The answer.
			 */
			public function reserveNext(int $registerId, int $schemaId, string $scopeKey): int|string {
				$this->calls[] = [$registerId, $schemaId, $scopeKey];

				return $this->answer;
			}//end reserveNext()
		};
	}//end setUp()

	/**
	 * Build the numberer under test.
	 *
	 * @return DocumentSeriesNumberer
	 */
	private function numberer(): DocumentSeriesNumberer {
		return new DocumentSeriesNumberer($this->cases, $this->caseTypes, $this->container, $this->logger);
	}//end numberer()

	/**
	 * The counter is asked for OpenRegister's SequenceService by name, on register and schema 0.
	 *
	 * @return void
	 */
	public function testTheCounterIsReservedOnTheOrganisationsScope(): void {
		$this->cases->expects(self::once())->method('loadCase')->with('case-1')
			->willReturn(['@self' => ['organisation' => ' org-a ']]);
		$this->container->expects(self::once())->method('get')
			->with('OCA\OpenRegister\Service\SequenceService')->willReturn($this->sequences);
		$this->caseTypes->expects(self::never())->method('effectiveCaseType');

		$number = $this->numberer()->issue('case-1', 'decision', 'B', new DateTimeImmutable('2031-03-04'));

		self::assertSame('B-2031-000007', $number);
		self::assertSame([[0, 0, 'dq-series|decision|org-a|2031']], $this->sequences->calls);
	}//end testTheCounterIsReservedOnTheOrganisationsScope()

	/**
	 * A case whose `@self` is not an array numbers in the instance-wide row.
	 *
	 * @return void
	 */
	public function testANonArraySelfNumbersInTheInstanceRow(): void {
		$this->cases->method('loadCase')->willReturn(['@self' => 'org-a']);
		$this->container->method('get')->willReturn($this->sequences);

		$this->numberer()->issue('case-1', 'decision', 'B', new DateTimeImmutable('2031-03-04'));

		self::assertSame([[0, 0, 'dq-series|decision||2031']], $this->sequences->calls);
	}//end testANonArraySelfNumbersInTheInstanceRow()

	/**
	 * A numeric-string answer from the counter is printed as a number.
	 *
	 * @return void
	 */
	public function testANumericStringAnswerIsPrintedPadded(): void {
		$this->sequences->answer = '42';
		$this->cases->method('loadCase')->willReturn(null);
		$this->container->method('get')->willReturn($this->sequences);

		self::assertSame('V-2031-000042', $this->numberer()->issue('missing', 'permit', 'V', new DateTimeImmutable('2031-01-01')));
	}//end testANumericStringAnswerIsPrintedPadded()

	/**
	 * A configured prefix is trimmed before it is printed.
	 *
	 * @return void
	 */
	public function testAConfiguredPrefixIsTrimmed(): void {
		$this->cases->method('loadCase')->willReturn(['caseType' => ' ct-1 ']);
		$this->caseTypes->expects(self::once())->method('effectiveCaseType')->with('ct-1')
			->willReturn(['documentSeries' => ['decision' => ['prefix' => '  BES ']]]);
		$this->container->method('get')->willReturn($this->sequences);

		self::assertSame('BES-2031-000007', $this->numberer()->issue('case-1', 'decision', 'B', new DateTimeImmutable('2031-01-01')));
	}//end testAConfiguredPrefixIsTrimmed()

	/**
	 * A blank or non-string configured prefix falls back to the caller's default.
	 *
	 * @return void
	 */
	public function testABlankOrNonStringPrefixUsesTheDefault(): void {
		$answers = [
			['documentSeries' => ['decision' => ['prefix' => '   ']]],
			['documentSeries' => ['decision' => ['prefix' => 12]]],
			['documentSeries' => ['permit' => ['prefix' => 'VER']]],
		];
		$this->cases->method('loadCase')->willReturn(['caseType' => 'ct-1']);
		$this->caseTypes->expects(self::exactly(3))->method('effectiveCaseType')->willReturnOnConsecutiveCalls(...$answers);
		$this->container->method('get')->willReturn($this->sequences);

		$numberer = $this->numberer();
		foreach ($answers as $ignored) {
			self::assertSame('B-2031-000007', $numberer->issue('case-1', 'decision', 'B', new DateTimeImmutable('2031-01-01')));
		}
	}//end testABlankOrNonStringPrefixUsesTheDefault()

	/**
	 * An unreadable case type is logged as a warning and the default prefix is printed.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeLogsAWarning(): void {
		$this->cases->method('loadCase')->willReturn(['caseType' => 'ct-1']);
		$this->caseTypes->method('effectiveCaseType')->willThrowException(new RuntimeException('gone'));
		$this->container->method('get')->willReturn($this->sequences);
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq document series: the case type could not be read, so the default prefix is used',
			['exception' => 'gone', 'series' => 'decision'],
		);
		$this->logger->expects(self::never())->method('error');

		self::assertSame('B-2031-000007', $this->numberer()->issue('case-1', 'decision', 'B', new DateTimeImmutable('2031-01-01')));
	}//end testAnUnreadableCaseTypeLogsAWarning()

	/**
	 * A failing counter is logged as an error and refused with the failure attached.
	 *
	 * @return void
	 */
	public function testAFailingCounterLogsAnErrorAndRefuses(): void {
		$failure = new RuntimeException('locked');
		$this->cases->method('loadCase')->willReturn([]);
		$this->container->method('get')->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with(
			'Dossiq document series: no number could be reserved',
			['exception' => 'locked', 'caseId' => 'case-1', 'series' => 'decision'],
		);

		try {
			$this->numberer()->issue('case-1', 'decision', 'B');
			self::fail('A document without a number must not be issued.');
		} catch (RefusedException $refusal) {
			self::assertSame('document-number-unavailable', $refusal->getRule());
			self::assertSame(
				'The document could not be given a number, so it was not created. Try again in a moment.',
				$refusal->getSentence()
			);
			self::assertSame($failure, $refusal->getPrevious());
		}
	}//end testAFailingCounterLogsAnErrorAndRefuses()

	/**
	 * A negative answer refuses without a previous failure and without logging.
	 *
	 * @return void
	 */
	public function testANegativeAnswerRefusesWithoutAPreviousFailure(): void {
		$this->sequences->answer = -3;
		$this->cases->method('loadCase')->willReturn([]);
		$this->container->method('get')->willReturn($this->sequences);
		$this->logger->expects(self::never())->method('error');

		try {
			$this->numberer()->issue('case-1', 'decision', 'B');
			self::fail('A negative number must not be printed.');
		} catch (RefusedException $refusal) {
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $refusal->getStatus());
			self::assertNull($refusal->getPrevious());
		}
	}//end testANegativeAnswerRefusesWithoutAPreviousFailure()

	/**
	 * A scope key of exactly 64 characters is used as is; one longer is hashed.
	 *
	 * @return void
	 */
	public function testTheScopeKeyIsHashedOnlyAboveSixtyFourCharacters(): void {
		// 'dq-series|' (10) + 's' (1) + '|' (1) + org + '|2031' (5) = 64 when org is 47 long.
		$fits = str_repeat('o', 47);
		$over = str_repeat('o', 48);
		$this->cases->method('loadCase')->willReturnOnConsecutiveCalls(
			['@self' => ['organisation' => $fits]],
			['@self' => ['organisation' => $over]],
		);
		$this->container->method('get')->willReturn($this->sequences);

		$numberer = $this->numberer();
		$numberer->issue('case-1', 's', 'B', new DateTimeImmutable('2031-01-01'));
		$numberer->issue('case-2', 's', 'B', new DateTimeImmutable('2031-01-01'));

		self::assertSame('dq-series|s|'.$fits.'|2031', $this->sequences->calls[0][2]);
		self::assertSame(64, strlen($this->sequences->calls[0][2]));
		self::assertSame('dq-series|'.substr(hash('sha256', 's|'.$over), 0, 40).'|2031', $this->sequences->calls[1][2]);
	}//end testTheScopeKeyIsHashedOnlyAboveSixtyFourCharacters()
}//end class
