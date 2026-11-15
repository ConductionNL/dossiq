<?php

/**
 * Unit tests for CaseRemedy: the remedy a case's own case type declares, and a
 * case that names no case type.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\CaseRemedy
 *
 * @uses \OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration
 * @uses \OCA\Dossiq\Service\Support\StoredArray
 */
class CaseRemedyDeclarationTest extends TestCase {

	/**
	 * The service over one stored case.
	 *
	 * @param array<string, mixed>|null $case     The stored case, null when there is none.
	 * @param array<string, mixed>      $caseType The case type the resolver answers for `ct-woo`.
	 * @param LoggerInterface|null      $logger   The logger, a quiet one when null.
	 * @param CaseTypeResolver|null     $resolver The resolver, one answering `$caseType` when null.
	 *
	 * @return CaseRemedy
	 */
	private function remedy(?array $case, array $caseType=[], ?LoggerInterface $logger=null, ?CaseTypeResolver $resolver=null): CaseRemedy {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn($case);
		if ($resolver === null) {
			$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
			$resolver->method('effectiveCaseType')->with('ct-woo')->willReturn($caseType);
		}

		return new CaseRemedy(
			store: $store,
			caseTypes: $resolver,
			declaration: new RemedyClauseDeclaration(l10n: new SourceL10n()),
			terms: $this->createMock(originalClassName: CaseTermsService::class),
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end remedy()

	/**
	 * The declaration is the case type's remedy, trimmed and lower-cased where it should be.
	 *
	 * @return void
	 */
	public function testTheDeclarationIsTheCaseTypesRemedyNormalised(): void {
		$declaration = $this->remedy(
			case: ['id' => 'case-1', 'caseType' => ' ct-woo '],
			caseType: [
				RemedyClauseDeclaration::DECLARATION => ['kind' => ' Bezwaar ', 'termDays' => '42', 'body' => ' het college ', 'label' => 'Bezwaar maken'],
			]
		)->declarationOf(caseId: 'case-1');

		$this->assertSame(
			expected: [
				'kind' => 'bezwaar',
				'termDays' => 42,
				'body' => 'het college',
				'enabled' => true,
				'caseType' => '',
				'id' => '',
				'label' => 'Bezwaar maken',
				'description' => '',
			],
			actual: $declaration
		);
	}//end testTheDeclarationIsTheCaseTypesRemedyNormalised()

	/**
	 * A case naming no case type declares nothing, says so, and asks no resolver.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeDeclaresNothingAndSaysSo(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq remedy: the case names no case type, so its decisions carry no clause',
			['caseId' => 'case-2'],
		);
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->expects(self::never())->method('effectiveCaseType');

		$remedy = $this->remedy(case: ['id' => 'case-2', 'caseType' => '  '], logger: $logger, resolver: $resolver);

		$this->assertSame(expected: '', actual: $remedy->declarationOf(caseId: ' case-2 ')['kind']);
	}//end testACaseWithoutACaseTypeDeclaresNothingAndSaysSo()

	/**
	 * A case that is not there logs the same warning and has no term.
	 *
	 * @return void
	 */
	public function testAMissingCaseLogsTheSameAndDeclaresNothing(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$this->assertSame(expected: 0, actual: $this->remedy(case: null, logger: $logger)->termDaysFor(caseId: 'case-3'));
	}//end testAMissingCaseLogsTheSameAndDeclaresNothing()

	/**
	 * An empty case id reads no case at all.
	 *
	 * @return void
	 */
	public function testAnEmptyCaseIdReadsNoCase(): void {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->expects(self::never())->method('loadCase');
		$remedy = new CaseRemedy(
			store: $store,
			caseTypes: $this->createMock(originalClassName: CaseTypeResolver::class),
			declaration: new RemedyClauseDeclaration(l10n: new SourceL10n()),
			terms: $this->createMock(originalClassName: CaseTermsService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: '', actual: $remedy->declarationOf(caseId: '  ')['body']);
	}//end testAnEmptyCaseIdReadsNoCase()
}//end class
