<?php

/**
 * A marker is a PAIR, and a declaration carrying half of it is refused by name.
 *
 * REQ-MRK-03. Four things are pinned here.
 *
 * 🔴 THE REFUSAL NAMES THE MARKER. A validator that answers "the declarations
 * are invalid" sends an administrator through a list of rows looking for the
 * one it meant. So every assertion below checks that the marker's own id is in
 * the sentence, not only that a sentence came back.
 *
 * 🔴 OPENING THE PANEL DOES NOT CLEAR THE MARKER, and this is the whole
 * difference from the per-user unread badge. There is no code path here that
 * clears a marker, because there is nothing to clear: the set is DERIVED from
 * the conditions, so a marker whose condition is still true is still in the
 * answer no matter who looked at what. The test drives that directly, by
 * evaluating twice over a case nothing changed on.
 *
 * 🔴 A CONDITION READING ROWS THAT WERE NOT FETCHED KEEPS ITS MARKER. This is
 * the assertion that stops the whole feature becoming a silent no-op in the
 * other direction. `adviceRequest` is a register object pointing back at the
 * case, not a property of it, so a save that did not fetch the rows knows
 * nothing about whether the advice arrived. Clearing on that would report work
 * as done on no evidence, and nothing anywhere would fail.
 *
 * 🔴 HANDLING THE WORK CLEARS IT, with nobody dismissing anything. The same
 * case with the failed document removed evaluates to no marker at all.
 *
 * A MARKER THAT IS STILL STANDING KEEPS THE MOMENT IT FIRST BECAME TRUE.
 * Restamping it on every save would make a three-week-old failed scan read as
 * new on each unrelated edit, which is how a marker stops being a signal.
 *
 * The declared vocabulary is pinned against the shipped register fragment the
 * way `CasePriorityDeclarationTest` pins the priority declaration: the panels
 * and the conditions live in two places, and the copy in PHP is the one that
 * drifts without anything failing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseAttentionMarkerService;
use OCA\Dossiq\Service\CaseTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CaseAttentionMarkerTest extends TestCase {

	/**
	 * The service, over a case type that answers what the test needs.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return CaseAttentionMarkerService The service.
	 */
	private function service(array $caseType = []): CaseAttentionMarkerService {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);

		// No settings service: `contextFor()` is not under test here and would
		// need a store. Every test below hands `evaluate()` its context
		// directly, which is also what keeps these assertions about the
		// CONDITIONS rather than about a query.
		return new CaseAttentionMarkerService(resolver: $resolver, logger: new NullLogger());
	}//end service()

	/**
	 * The app root.
	 *
	 * @return string The absolute path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * A plain case of the type under test.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function plainCase(): array {
		return ['caseType' => 'ct-1'];
	}//end plainCase()

	/**
	 * The related rows one advice request makes.
	 *
	 * @param string $status   Where the request stands.
	 * @param string $deadline The date it was asked for.
	 *
	 * @return array<string, array<int, array<string, string>>> The context.
	 */
	private function adviceContext(string $status, string $deadline): array {
		return [
			'adviceRequests' => [
				['question' => 'Advies brandveiligheid', 'status' => $status, 'deadline' => $deadline],
			],
		];
	}//end adviceContext()

	/**
	 * A marker declared with a raise condition and no clear condition is
	 * refused, naming the marker.
	 *
	 * @return void
	 */
	public function testAMarkerWithoutAClearingConditionIsRefused(): void {
		$problems = $this->service()->validateDeclarations(
			declarations: [
				[
					'id' => 'advies-te-laat',
					'tab' => 'case-work-panel',
					'raiseWhen' => 'advice-request-overdue',
				],
			]
		);

		self::assertCount(expectedCount: 1, haystack: $problems);
		self::assertStringContainsString(needle: 'advies-te-laat', haystack: $problems[0]);
		self::assertStringContainsString(needle: 'clears it', haystack: $problems[0]);
	}//end testAMarkerWithoutAClearingConditionIsRefused()

	/**
	 * Every other way a declaration can be half-written is refused by name.
	 *
	 * @return void
	 */
	public function testEveryHalfWrittenDeclarationIsRefusedByName(): void {
		$service = $this->service();

		$noPanel = $service->validateDeclarations(
			declarations: [['id' => 'nowhere', 'tab' => 'the-side', 'raiseWhen' => 'term-exceeded', 'clearWhen' => 'condition-no-longer-true']]
		);
		self::assertCount(expectedCount: 1, haystack: $noPanel);
		self::assertStringContainsString(needle: 'nowhere', haystack: $noPanel[0]);
		self::assertStringContainsString(needle: 'panel', haystack: $noPanel[0]);

		$noCondition = $service->validateDeclarations(
			declarations: [['id' => 'vague', 'tab' => 'case-files', 'raiseWhen' => 'something-is-wrong', 'clearWhen' => 'condition-no-longer-true']]
		);
		self::assertCount(expectedCount: 1, haystack: $noCondition);
		self::assertStringContainsString(needle: 'vague', haystack: $noCondition[0]);

		$twice = $service->validateDeclarations(
			declarations: [
				['id' => 'same', 'tab' => 'case-files', 'raiseWhen' => 'advice-request-overdue', 'clearWhen' => 'condition-no-longer-true'],
				['id' => 'same', 'tab' => 'case-work-panel', 'raiseWhen' => 'term-exceeded', 'clearWhen' => 'condition-no-longer-true'],
			]
		);
		self::assertCount(expectedCount: 1, haystack: $twice);
		self::assertStringContainsString(needle: 'twice', haystack: $twice[0]);
	}//end testEveryHalfWrittenDeclarationIsRefusedByName()

	/**
	 * Every shipped marker is a whole declaration.
	 *
	 * The set this app ships is the set no administrator ever looked at, so it
	 * is the one most likely to carry the defect this requirement refuses.
	 *
	 * @return void
	 */
	public function testEveryShippedMarkerIsWhole(): void {
		self::assertSame(
			expected: [],
			actual: $this->service()->validateDeclarations(declarations: CaseAttentionMarkerService::SHIPPED_MARKERS)
		);
	}//end testEveryShippedMarkerIsWhole()

	/**
	 * An advice request past its date marks the Work panel, naming the reason.
	 *
	 * @return void
	 */
	public function testAnOverdueAdviceRequestMarksTheWorkPanel(): void {
		$markers = $this->service()->evaluate(
			case: $this->plainCase(),
			now: new DateTimeImmutable('2026-06-01'),
			context: $this->adviceContext(status: 'requested', deadline: '2026-05-01')
		);

		self::assertSame(expected: ['advice-request-overdue'], actual: array_column($markers, 'marker'));
		self::assertSame(expected: 'case-work-panel', actual: $markers[0]['tab']);
		self::assertNotSame(expected: '', actual: $markers[0]['reason'], message: 'the marker names why it is there');
	}//end testAnOverdueAdviceRequestMarksTheWorkPanel()

	/**
	 * Reading the case again changes nothing about the marker.
	 *
	 * The per-user unread badge would have gone by now. This one is not per
	 * user and has nothing to do with who looked.
	 *
	 * @return void
	 */
	public function testOpeningThePanelDoesNotClearTheMarker(): void {
		$service = $this->service();
		$case = $this->plainCase();
		$context = $this->adviceContext(status: 'requested', deadline: '2026-05-01');

		$first = $service->evaluate(
			case: $case,
			now: new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
			context: $context
		);
		// The case, as it is stored after that save, carries the marker. A
		// handler then opens the panel, which writes nothing at all.
		$case['attentionMarkers'] = $first;
		$second = $service->evaluate(
			case: $case,
			now: new DateTimeImmutable('2026-06-22T16:00:00+00:00'),
			context: $context
		);

		self::assertSame(expected: $first, actual: $second, message: 'three weeks and a visit change nothing');
		self::assertSame(
			expected: '2026-06-01T09:00:00+00:00',
			actual: $second[0]['raisedAt'],
			message: 'a standing marker keeps the moment it first became true'
		);
	}//end testOpeningThePanelDoesNotClearTheMarker()

	/**
	 * Handling the work clears the marker, and nobody dismissed it.
	 *
	 * @return void
	 */
	public function testHandlingTheWorkClearsTheMarker(): void {
		$service = $this->service();
		$case = $this->plainCase();
		$now = new DateTimeImmutable('2026-06-01');

		$case['attentionMarkers'] = $service->evaluate(
			case: $case,
			now: $now,
			context: $this->adviceContext(status: 'requested', deadline: '2026-05-01')
		);
		self::assertCount(expectedCount: 1, haystack: $case['attentionMarkers']);

		// The work: the advice arrived.
		$answered = $this->adviceContext(status: 'received', deadline: '2026-05-01');

		self::assertSame(
			expected: [],
			actual: $service->evaluate(case: $case, now: $now, context: $answered),
			message: 'the condition stopped being true, so the marker is gone'
		);
		self::assertSame(
			expected: ['attentionMarkers' => [], 'hasAttentionMarkers' => false],
			actual: $service->resolve(case: $case, now: $now, context: $answered)
		);
	}//end testHandlingTheWorkClearsTheMarker()

	/**
	 * 🔴 A SAVE THAT DID NOT FETCH THE ROWS KEEPS THE MARKER IT CANNOT JUDGE.
	 *
	 * `adviceRequest` is a register object pointing back at the case, never a
	 * property of it, so an empty context means "not asked" and not "asked, and
	 * there is nothing". Clearing on the first would report work as done on no
	 * evidence at all, on every unrelated save of the case, and nothing
	 * anywhere would fail.
	 *
	 * @return void
	 */
	public function testAConditionWhoseRowsWereNotFetchedKeepsItsMarker(): void {
		$service = $this->service();
		$now = new DateTimeImmutable('2026-06-01');

		$case = $this->plainCase();
		$case['attentionMarkers'] = $service->evaluate(
			case: $case,
			now: $now,
			context: $this->adviceContext(status: 'requested', deadline: '2026-05-01')
		);
		$standing = $case['attentionMarkers'];

		// An unrelated save. Nothing fetched the advice requests.
		$after = $service->evaluate(case: $case, now: $now, context: []);

		self::assertSame(expected: $standing, actual: $after, message: 'the marker stands, unchanged');
	}//end testAConditionWhoseRowsWereNotFetchedKeepsItsMarker()

	/**
	 * A case that never had the marker does not gain one from an empty
	 * context either.
	 *
	 * The mirror of the test above: keeping what stands must not become
	 * inventing what does not.
	 *
	 * @return void
	 */
	public function testAnEmptyContextInventsNothing(): void {
		self::assertSame(
			expected: [],
			actual: $this->service()->evaluate(
				case: $this->plainCase(),
				now: new DateTimeImmutable('2026-06-01'),
				context: []
			)
		);
	}//end testAnEmptyContextInventsNothing()

	/**
	 * A case type that declares its own markers gets exactly those.
	 *
	 * @return void
	 */
	public function testADeclaredSetReplacesTheShippedOne(): void {
		$service = $this->service(
			caseType: [
				'attentionMarkers' => [
					[
						'id' => 'past-the-date',
						'tab' => 'case-work-panel',
						'raiseWhen' => 'term-exceeded',
						'clearWhen' => 'condition-no-longer-true',
					],
				],
			]
		);

		$case = $this->plainCase();
		$case['deadline'] = '2026-01-01';

		$markers = $service->evaluate(
			case: $case,
			now: new DateTimeImmutable('2026-06-01'),
			context: $this->adviceContext(status: 'requested', deadline: '2026-05-01')
		);

		self::assertSame(
			expected: ['past-the-date'],
			actual: array_column($markers, 'marker'),
			message: 'the overdue advice raises nothing on a case type that did not declare it'
		);
	}//end testADeclaredSetReplacesTheShippedOne()

	/**
	 * A half-written declaration raises nothing rather than something
	 * half-described.
	 *
	 * @return void
	 */
	public function testABrokenDeclarationRaisesNothing(): void {
		$service = $this->service(
			caseType: [
				'attentionMarkers' => [
					['id' => 'advies-te-laat', 'tab' => 'case-work-panel', 'raiseWhen' => 'advice-request-overdue'],
				],
			]
		);

		self::assertSame(expected: [], actual: $service->declarationsFor(caseTypeId: 'ct-1'));
		self::assertSame(
			expected: [],
			actual: $service->evaluate(
				case: $this->plainCase(),
				now: new DateTimeImmutable('2026-06-01'),
				context: $this->adviceContext(status: 'requested', deadline: '2026-05-01')
			)
		);
	}//end testABrokenDeclarationRaisesNothing()

	/**
	 * The panels and the conditions this class knows are the ones the schema
	 * declares, and the panels are the ones the case page renders.
	 *
	 * @return void
	 */
	public function testTheVocabularyIsTheDeclaredOne(): void {
		$fragment = json_decode(
			(string)file_get_contents($this->root() . '/lib/Settings/register.d/38-markers-and-assessments.json'),
			true
		);
		self::assertIsArray(actual: $fragment);

		$declaration = (array)$fragment['components']['schemas']['caseType']['properties']['attentionMarkers']['items']['properties'];

		self::assertSame(
			expected: CaseAttentionMarkerService::PANELS,
			actual: (array)$declaration['tab']['enum'],
			message: 'the panels this service knows are the panels the schema offers'
		);
		self::assertSame(
			expected: CaseAttentionMarkerService::RAISE_CONDITIONS,
			actual: (array)$declaration['raiseWhen']['enum'],
			message: 'a declared condition nothing evaluates is a marker that never appears'
		);
		self::assertSame(
			expected: [CaseAttentionMarkerService::CLEAR_CONDITION],
			actual: (array)$declaration['clearWhen']['enum'],
			message: 'clearing is doing the work, so there is exactly one clearing'
		);

		// 🔴 EVERY CONDITION IS EVALUATED SOMEWHERE. A condition in the
		// vocabulary that `reasonFor()` has no arm for answers the empty
		// string, which reads exactly like a condition that is false, so the
		// marker would never appear and nothing would say why. The arms are
		// private, so the check is that each condition either reads the case
		// payload or names a context key, and that every named key is one
		// `contextFor()` actually fetches.
		foreach (CaseAttentionMarkerService::RAISE_CONDITIONS as $condition) {
			$needs = (CaseAttentionMarkerService::CONDITION_CONTEXT[$condition] ?? '');
			if ($needs === '') {
				continue;
			}

			self::assertSame(
				expected: 'adviceRequests',
				actual: $needs,
				message: sprintf('%s reads rows nothing fetches', $condition)
			);
		}

		$manifest = json_decode(
			(string)file_get_contents($this->root() . '/src/manifest.json'),
			true
		);
		self::assertIsArray(actual: $manifest);

		$detail = [];
		foreach ((array)$manifest['pages'] as $page) {
			if (($page['id'] ?? '') === 'CaseDetail') {
				$detail = (array)$page;
				break;
			}
		}

		$panels = [];
		foreach ((array)$detail['config']['widgets'] as $widget) {
			if (($widget['id'] ?? '') !== 'case-panels') {
				continue;
			}

			$panels = array_column((array)$widget['content']['tabs'], 'widgetId');
		}

		self::assertSame(
			expected: [],
			actual: array_diff(CaseAttentionMarkerService::PANELS, $panels),
			message: 'a marker may not point at a panel the case page does not render'
		);
	}//end testTheVocabularyIsTheDeclaredOne()
}//end class
