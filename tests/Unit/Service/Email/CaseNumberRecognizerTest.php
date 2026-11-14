<?php

/**
 * CaseNumberRecognizer unit tests
 *
 * The configured pattern is validated before a single mailbox is read, the
 * candidates are pulled from the text deduplicated, and a candidate becomes a
 * linked case only when exactly one readable case carries that identifier and
 * the row names a numeric register and schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pattern validation, candidate extraction and case resolution.
 *
 * @covers \OCA\Dossiq\Service\Email\CaseNumberRecognizer
 */
final class CaseNumberRecognizerTest extends TestCase {

	/**
	 * Where the configured pattern is read from.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * Answers whether the owner may read a case.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $access;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The mailbox owner.
	 *
	 * @var IUser&MockObject
	 */
	private IUser $owner;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->access = $this->createMock(CaseAccessGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->owner = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The class under test.
	 *
	 * @return CaseNumberRecognizer The recognizer.
	 */
	private function recognizer(): CaseNumberRecognizer {
		return new CaseNumberRecognizer(
			settingsService: $this->settings,
			caseAccess: $this->access,
			logger: $this->logger,
		);
	}//end recognizer()

	/**
	 * An object service whose numeric search answers the given rows, or throws.
	 *
	 * @param array<int, mixed> $rows  The rows to answer.
	 * @param bool              $fails Whether the search throws instead.
	 *
	 * @return object The object service, with the queries it received in `$queries`.
	 */
	private function objectService(array $rows, bool $fails = false): object {
		return new class($rows, $fails) {
			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			/**
			 * @param array<int, mixed> $rows  Rows.
			 * @param bool              $fails Throw.
			 */
			public function __construct(private array $rows, private bool $fails) {
			}

			/**
			 * @param array<string, mixed> $query Query.
			 * @param mixed                ...$scope Scope.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjects(array $query, mixed ...$scope): array {
				$this->queries[] = $query;
				if ($this->fails === true) {
					throw new RuntimeException('database gone');
				}

				return $this->rows;
			}

			/**
			 * @param string               $register Register slug.
			 * @param string               $schema   Schema slug.
			 * @param array<string, mixed> $filters  Filters.
			 * @param mixed                ...$scope Scope.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, mixed ...$scope): array {
				return $this->searchObjects(['slugs' => [$register, $schema]] + $filters);
			}
		};
	}//end objectService()

	public function testAnEmptyPatternIsRefused(): void {
		$this->assertSame('The pattern is empty.', $this->recognizer()->validatePattern(pattern: '  '));
	}//end testAnEmptyPatternIsRefused()

	public function testAPatternThatDoesNotCompileIsRefused(): void {
		$this->assertSame('The pattern does not compile.', $this->recognizer()->validatePattern(pattern: '/(unclosed/'));
	}//end testAPatternThatDoesNotCompileIsRefused()

	public function testAPatternWithoutACaptureGroupIsRefused(): void {
		$this->assertSame(
			'The pattern has no capture group for the case number.',
			$this->recognizer()->validatePattern(pattern: '/\d{4}-\d{6}/')
		);
	}//end testAPatternWithoutACaptureGroupIsRefused()

	public function testABracketDelimitedPatternWithACaptureGroupIsAccepted(): void {
		$this->assertNull($this->recognizer()->validatePattern(pattern: '{(\d{4}-\d{6})}i'));
		$this->assertNull($this->recognizer()->validatePattern(pattern: CaseNumberRecognizer::DEFAULT_PATTERN));
	}//end testABracketDelimitedPatternWithACaptureGroupIsAccepted()

	public function testAnUnsetPatternFallsBackToTheDefault(): void {
		$this->settings->expects($this->once())->method('getConfigValue')
			->with(CaseNumberRecognizer::PATTERN_KEY)->willReturn('   ');

		$this->assertSame(CaseNumberRecognizer::DEFAULT_PATTERN, $this->recognizer()->loadPattern());
	}//end testAnUnsetPatternFallsBackToTheDefault()

	public function testAnUnusableConfiguredPatternStopsMatchingAndIsLogged(): void {
		$this->settings->method('getConfigValue')->willReturn('/no-group/');
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: email case matching refused to run, the configured email_case_matching_pattern is unusable: '
				. 'The pattern has no capture group for the case number.',
			['app' => 'dossiq']
		);

		$this->assertNull($this->recognizer()->loadPattern());
	}//end testAnUnusableConfiguredPatternStopsMatchingAndIsLogged()

	public function testCandidatesAreTakenFromTheFirstGroupOnceEach(): void {
		$text = 'Re: [ZAAK-2026-000142] zie ook 2026-000142 en 2025-0099, niet x2026-000143';

		$this->assertSame(
			['2026-000142', '2025-0099'],
			$this->recognizer()->extractCaseNumberCandidates(text: $text, pattern: CaseNumberRecognizer::DEFAULT_PATTERN)
		);
	}//end testCandidatesAreTakenFromTheFirstGroupOnceEach()

	public function testBlankTextOrNoMatchYieldsNoCandidates(): void {
		$recognizer = $this->recognizer();

		$this->assertSame([], $recognizer->extractCaseNumberCandidates(text: " \n", pattern: CaseNumberRecognizer::DEFAULT_PATTERN));
		$this->assertSame([], $recognizer->extractCaseNumberCandidates(text: 'geen nummer', pattern: CaseNumberRecognizer::DEFAULT_PATTERN));
	}//end testBlankTextOrNoMatchYieldsNoCandidates()

	public function testOneReadableExactMatchBecomesALinkedCase(): void {
		$service = $this->objectService(
			[
				['identifier' => '2026-000142', '@self' => ['id' => 'uuid-142', 'register' => '4', 'schema' => 9]],
				['identifier' => '2026-0001420', '@self' => ['id' => 'uuid-other']],
			]
		);
		$this->access->expects($this->once())->method('hasCaseReadAccess')
			->with('uuid-142', $this->owner)->willReturn(true);

		$cases = $this->recognizer()->resolveCases(
			candidates: ['2026-000142'],
			objectService: $service,
			register: '1',
			schema: '2',
			owner: $this->owner
		);

		$this->assertSame(
			[['uuid' => 'uuid-142', 'identifier' => '2026-000142', 'registerId' => 4, 'schemaId' => 9]],
			$cases
		);
		$this->assertSame('2026-000142', $service->queries[0]['identifier']);
		$this->assertTrue($service->queries[0]['_multitenancy_explicit']);
		$this->assertSame(['register' => 1, 'schema' => 2], $service->queries[0]['@self']);
	}//end testOneReadableExactMatchBecomesALinkedCase()

	public function testTheConfiguredIdsAreUsedWhenTheRowCarriesNone(): void {
		$service = $this->objectService([['identifier' => '2026-000142', 'uuid' => 'uuid-142', '@self' => 'not-a-block']]);
		$this->access->method('hasCaseReadAccess')->willReturn(true);

		$cases = $this->recognizer()->resolveCases(
			candidates: ['2026-000142'],
			objectService: $service,
			register: '3',
			schema: '5',
			owner: $this->owner
		);

		$this->assertSame(
			[['uuid' => 'uuid-142', 'identifier' => '2026-000142', 'registerId' => 3, 'schemaId' => 5]],
			$cases
		);
	}//end testTheConfiguredIdsAreUsedWhenTheRowCarriesNone()

	public function testTheSameCaseNamedTwiceIsLinkedOnce(): void {
		$service = $this->objectService([['identifier' => '2026-000142', 'id' => 'uuid-142']]);
		$this->access->method('hasCaseReadAccess')->willReturn(true);

		$cases = $this->recognizer()->resolveCases(
			candidates: ['2026-000142', '2026-000142'],
			objectService: $service,
			register: '3',
			schema: '5',
			owner: $this->owner
		);

		$this->assertCount(1, $cases);
	}//end testTheSameCaseNamedTwiceIsLinkedOnce()

	public function testAnAmbiguousNumberLinksNothingAndIsLogged(): void {
		$service = $this->objectService(
			[
				['identifier' => '2026-000142', 'id' => 'a'],
				['identifier' => '2026-000142', 'id' => 'b'],
			]
		);
		$this->access->expects($this->never())->method('hasCaseReadAccess');
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: a case number resolves to more than one case; the mail is linked to none of them',
			['app' => 'dossiq', 'case' => '2026-000142', 'matches' => 2]
		);

		$this->assertSame(
			[],
			$this->recognizer()->resolveCases(candidates: ['2026-000142'], objectService: $service, register: '3', schema: '5', owner: $this->owner)
		);
	}//end testAnAmbiguousNumberLinksNothingAndIsLogged()

	public function testACaseTheOwnerCannotReadIsNotLinked(): void {
		$service = $this->objectService([['identifier' => '2026-000142', 'id' => 'uuid-142']]);
		$this->access->method('hasCaseReadAccess')->willReturn(false);

		$this->assertSame(
			[],
			$this->recognizer()->resolveCases(candidates: ['2026-000142'], objectService: $service, register: '3', schema: '5', owner: $this->owner)
		);
	}//end testACaseTheOwnerCannotReadIsNotLinked()

	public function testARowWithoutAnIdIsNotLinkedAndAccessIsNotAsked(): void {
		$service = $this->objectService([['identifier' => '2026-000142']]);
		$this->access->expects($this->never())->method('hasCaseReadAccess');

		$this->assertSame(
			[],
			$this->recognizer()->resolveCases(candidates: ['2026-000142'], objectService: $service, register: '3', schema: '5', owner: $this->owner)
		);
	}//end testARowWithoutAnIdIsNotLinkedAndAccessIsNotAsked()

	public function testACaseWithoutNumericIdsIsNotLinkedToIdZero(): void {
		$service = $this->objectService(
			[['identifier' => '2026-000142', '@self' => ['id' => 'uuid-142', 'register' => 'zaken', 'schema' => ['x']]]]
		);
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: a matched case carries no numeric register or schema id; it is not linked rather than linked to id 0',
			['app' => 'dossiq', 'case' => '2026-000142']
		);

		$this->assertSame(
			[],
			$this->recognizer()->resolveCases(candidates: ['2026-000142'], objectService: $service, register: 'zaken', schema: 'zaak', owner: $this->owner)
		);
		$this->assertSame(['zaken', 'zaak'], $service->queries[0]['slugs']);
	}//end testACaseWithoutNumericIdsIsNotLinkedToIdZero()

	public function testASearchFailureIsLoggedAndLinksNothing(): void {
		$service = $this->objectService([], fails: true);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: resolving a case number failed: database gone',
			['app' => 'dossiq']
		);

		$this->assertSame(
			[],
			$this->recognizer()->resolveCases(candidates: ['2026-000142'], objectService: $service, register: '3', schema: '5', owner: $this->owner)
		);
	}//end testASearchFailureIsLoggedAndLinksNothing()
}//end class
