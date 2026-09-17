<?php

/**
 * Sending a decision starts the remedy clock, from the case type's declaration.
 *
 * 🔴 THE FAILURE THIS GUARDS IS A CLOCK THAT DISAGREES WITH THE CLAUSE. A
 * besluit printing forty-two days over a term instance of six weeks answers
 * "still open to bezwaar" wrongly for the last few days, and nobody sees it
 * until a late objection is accepted or a timely one refused. So the clock is
 * bound from the same declaration the clause is printed from, driven here over
 * the REAL declaration reader rather than a double of it.
 *
 * 🔑 NO DECLARATION, NO CLOCK. A default term would make the case answer a
 * question about a remedy its case type never declared.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RemedyTermBindingTest extends TestCase {

	/**
	 * What the terms service was asked to bind.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $bound = [];

	/**
	 * The resolver over a case type declaring the given remedy.
	 *
	 * @param array<string, mixed> $remedy The remedy declaration, [] for none.
	 *
	 * @return CaseRemedy The collaborator under test.
	 */
	private function remedyOver(array $remedy): CaseRemedy {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(['id' => 'case-1', 'caseType' => 'ct-omgeving']);

		$caseType = ['title' => 'Omgevingsvergunning'];
		if ($remedy !== []) {
			$caseType[RemedyClauseDeclaration::DECLARATION] = $remedy;
		}

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);

		$terms = $this->createMock(originalClassName: CaseTermsService::class);
		$terms->method('bindLeadTime')->willReturnCallback(
			function (string $caseId, string $kind, int $days, DateTimeImmutable $start, array $extra = []): array {
				$this->bound[] = [
					'caseId' => $caseId,
					'kind' => $kind,
					'days' => $days,
					'start' => $start->format('Y-m-d'),
					'extra' => $extra,
				];

				return ['id' => 'term-1', 'kind' => $kind];
			}
		);

		return new CaseRemedy(
			store: $store,
			caseTypes: $resolver,
			declaration: new RemedyClauseDeclaration(),
			terms: $terms,
			logger: new NullLogger(),
		);
	}//end remedyOver()

	/**
	 * The besluit going out binds a remedy term of the declared length.
	 *
	 * @return void
	 */
	public function testSendingBindsARemedyTermOfTheDeclaredLength(): void {
		$remedy = $this->remedyOver(remedy: ['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college']);

		$instance = $remedy->bindTerm(
			caseId: 'case-1',
			decisionId: 'besluit-1',
			sentOn: new DateTimeImmutable('2026-09-01'),
		);

		self::assertNotNull(actual: $instance);
		self::assertCount(expectedCount: 1, haystack: $this->bound);
		self::assertSame(expected: TermKind::REMEDY, actual: $this->bound[0]['kind']);
		self::assertSame(expected: 42, actual: $this->bound[0]['days']);
		self::assertSame(expected: '2026-09-01', actual: $this->bound[0]['start']);
		// The decision the clock belongs to. A case carries several, and a
		// remedy term naming none answers "still open" without saying to what.
		self::assertSame(expected: 'besluit-1', actual: $this->bound[0]['extra']['decision']);
	}//end testSendingBindsARemedyTermOfTheDeclaredLength()

	/**
	 * The clause and the clock read the same declaration.
	 *
	 * @return void
	 */
	public function testTheClauseAndTheClockAgree(): void {
		$remedy = $this->remedyOver(remedy: ['kind' => 'bezwaar', 'termDays' => 28, 'body' => 'het college']);

		self::assertStringContainsString(needle: '28 dagen', haystack: $remedy->clauseFor(caseId: 'case-1'));
		self::assertSame(expected: 28, actual: $remedy->termDaysFor(caseId: 'case-1'));
	}//end testTheClauseAndTheClockAgree()

	/**
	 * A case type declaring no remedy binds no clock and prints no clause.
	 *
	 * @return void
	 */
	public function testNoDeclarationBindsNothing(): void {
		$remedy = $this->remedyOver(remedy: []);

		self::assertNull(actual: $remedy->bindTerm(caseId: 'case-1', decisionId: 'besluit-1'));
		self::assertSame(expected: [], actual: $this->bound);
		self::assertSame(expected: '', actual: $remedy->clauseFor(caseId: 'case-1'));
	}//end testNoDeclarationBindsNothing()

	/**
	 * A case type that could not be read is thrown, not read as "no remedy".
	 *
	 * Swallowed, it would print a besluit with no bezwaarclausule on the
	 * strength of a failed read, and nothing would say the clause was missing
	 * for that reason rather than by choice.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeIsNotReadAsNoRemedy(): void {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(['id' => 'case-1', 'caseType' => 'ct-omgeving']);
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willThrowException(new \RuntimeException('register down'));

		$remedy = new CaseRemedy(
			store: $store,
			caseTypes: $resolver,
			declaration: new RemedyClauseDeclaration(),
			terms: $this->createMock(originalClassName: CaseTermsService::class),
			logger: new NullLogger(),
		);

		$this->expectException(exception: \RuntimeException::class);
		$remedy->clauseFor(caseId: 'case-1');
	}//end testAnUnreadableCaseTypeIsNotReadAsNoRemedy()

	/**
	 * A decision sent fifty days ago on a 42-day term reads as expired.
	 *
	 * The question "is this still open to bezwaar" is answered by the case's
	 * own term read, not by arithmetic: the remedy term is an instance like the
	 * others, so `termsForCase()` says it is past its end.
	 *
	 * @return void
	 */
	public function testARemedyTermPastItsEndReadsAsExpired(): void {
		$today = new DateTimeImmutable('2026-10-21');
		$sent = $today->modify('-50 days');

		$termService = $this->createMock(originalClassName: TermijnService::class);
		$termService->method('instancesForCase')->willReturn([
			[
				'id' => 'term-remedy',
				'kind' => TermKind::REMEDY,
				'startDate' => $sent->format('Y-m-d'),
				'endDateCurrent' => $sent->modify('+42 days')->format('Y-m-d'),
				'status' => 'lopend',
			],
		]);

		$terms = new CaseTermsService(
			termService: $termService,
			declarations: $this->createMock(originalClassName: TermDeclarationReader::class),
			timers: $this->createMock(originalClassName: TermijnTimerService::class),
			logger: new NullLogger(),
		);

		$read = $terms->termsForCase(caseId: 'case-1', now: $today);

		self::assertSame(expected: TermKind::REMEDY, actual: $read[0]['kind']);
		self::assertTrue(condition: $read[0]['overdue']);
		self::assertSame(expected: -8, actual: $read[0]['daysLeft']);
	}//end testARemedyTermPastItsEndReadsAsExpired()

	/**
	 * The remedy clock is its own kind, and a citizen surface does not show it.
	 *
	 * Its own kind keeps it out of the phase strip and the progress figure,
	 * which would otherwise read a closed case as unfinished for six weeks.
	 *
	 * @return void
	 */
	public function testTheRemedyKindIsKnownAndStaysOffTheCitizenSurface(): void {
		self::assertTrue(condition: TermKind::isKnown(kind: TermKind::REMEDY));
		self::assertSame(expected: TermKind::REMEDY, actual: TermKind::ofInstance(instance: ['kind' => 'remedy']));
		self::assertFalse(condition: TermKind::isCitizenVisible(kind: TermKind::REMEDY));
	}//end testTheRemedyKindIsKnownAndStaysOffTheCitizenSurface()
}//end class
