<?php

/**
 * Who a piece of work goes to, asked once.
 *
 * The rule these pin was written once and applied in one place, while two other
 * places that create tasks each did something different: `CreateTaskHandler`
 * copied the authored string onto the task, and the status checklist named
 * nobody. Both failures are silent. A task whose assignee is the literal
 * `{{ case.assignee }}` is addressed to a principal nothing answers to, and a
 * task whose assignee is `''` drops the schema's `taskAssigned` notification on
 * the floor: the work exists and nobody is told.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\AssigneeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for AssigneeResolver.
 *
 * @covers \OCA\Dossiq\Service\AssigneeResolver
 */
class AssigneeResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var AssigneeResolver
	 */
	private AssigneeResolver $resolver;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = new AssigneeResolver(new NullLogger());
	}//end setUp()

	/**
	 * A literal names the principal it says.
	 *
	 * @return void
	 */
	public function testALiteralResolvesToItself(): void {
		self::assertSame(
			'behandelaars',
			$this->resolver->resolve(primary: 'behandelaars', fallback: '', case: ['id' => 'c'])
		);
	}//end testALiteralResolvesToItself()

	/**
	 * The shipped spelling reads the case's handler.
	 *
	 * @return void
	 */
	public function testTheShippedSpellingReadsTheCasesHandler(): void {
		self::assertSame(
			'alice',
			$this->resolver->resolve(
				primary: '{{ case.assignee }}',
				fallback: '',
				case: ['id' => 'c', 'assignee' => 'alice']
			)
		);
	}//end testTheShippedSpellingReadsTheCasesHandler()

	/**
	 * The case is offered without the prefix too, so both spellings work.
	 *
	 * @return void
	 */
	public function testTheUnprefixedSpellingWorksAsWell(): void {
		self::assertSame(
			'alice',
			$this->resolver->resolve(
				primary: '{{ assignee }}',
				fallback: '',
				case: ['id' => 'c', 'assignee' => 'alice']
			)
		);
	}//end testTheUnprefixedSpellingWorksAsWell()

	/**
	 * 🔴 An unresolved template is nobody, never the template itself.
	 *
	 * Storing the literal is what orphaned every applicant task live: the
	 * resume guard compared real uids against the un-rendered placeholder and
	 * refused all of them.
	 *
	 * @return void
	 */
	public function testAnUnresolvedTemplateNamesNobodyRatherThanItself(): void {
		$resolved = $this->resolver->resolve(
			primary: '{{ case.assignee }}',
			fallback: '',
			case: ['id' => 'c']
		);

		self::assertSame('', $resolved);
		self::assertStringNotContainsString('{{', $resolved);
	}//end testAnUnresolvedTemplateNamesNobodyRatherThanItself()

	/**
	 * The declared fallback takes over when the primary names nobody.
	 *
	 * @return void
	 */
	public function testTheDeclaredFallbackTakesOver(): void {
		self::assertSame(
			'behandelaars',
			$this->resolver->resolve(
				primary: '{{ case.assignee }}',
				fallback: 'behandelaars',
				case: ['id' => 'c']
			)
		);
	}//end testTheDeclaredFallbackTakesOver()

	/**
	 * A resolvable primary wins over the fallback.
	 *
	 * @return void
	 */
	public function testAResolvablePrimaryWinsOverTheFallback(): void {
		self::assertSame(
			'alice',
			$this->resolver->resolve(
				primary: '{{ case.assignee }}',
				fallback: 'behandelaars',
				case: ['id' => 'c', 'assignee' => 'alice']
			)
		);
	}//end testAResolvablePrimaryWinsOverTheFallback()

	/**
	 * A fallback that itself names nobody answers nobody.
	 *
	 * @return void
	 */
	public function testAFallbackThatNamesNobodyAnswersNobody(): void {
		self::assertSame(
			'',
			$this->resolver->resolve(
				primary: '{{ case.assignee }}',
				fallback: '{{ case.caseTypeOwner }}',
				case: ['id' => 'c']
			)
		);
	}//end testAFallbackThatNamesNobodyAnswersNobody()

	/**
	 * A value that renders to a structure is not a name.
	 *
	 * @return void
	 */
	public function testAStructureIsNotAName(): void {
		self::assertSame(
			'',
			$this->resolver->resolve(
				primary: '{{ case.team }}',
				fallback: '',
				case: ['id' => 'c', 'team' => ['id' => 'rol-7', 'name' => 'Vergunningen']]
			)
		);
	}//end testAStructureIsNotAName()

	/**
	 * Whitespace around an authored value is not part of the principal.
	 *
	 * @return void
	 */
	public function testSurroundingWhitespaceIsNotPartOfTheName(): void {
		self::assertSame(
			'behandelaars',
			$this->resolver->resolve(primary: '  behandelaars  ', fallback: '', case: [])
		);
	}//end testSurroundingWhitespaceIsNotPartOfTheName()

	/**
	 * Nothing authored names nobody, and does not throw.
	 *
	 * @return void
	 */
	public function testNothingAuthoredNamesNobody(): void {
		self::assertSame('', $this->resolver->resolve(primary: '', fallback: '', case: []));
	}//end testNothingAuthoredNamesNobody()

	/**
	 * The refusal clause distinguishes no fallback from a dead one.
	 *
	 * Two different problems for the reader: one is a declaration that never
	 * named a second choice, the other is a second choice that named nobody.
	 *
	 * @return void
	 */
	public function testTheRefusalClauseTellsTheTwoProblemsApart(): void {
		self::assertStringContainsString(
			'no assigneeFallback',
			$this->resolver->refusalReason(fallback: '')
		);
		self::assertStringContainsString(
			'behandelaars',
			$this->resolver->refusalReason(fallback: 'behandelaars')
		);
	}//end testTheRefusalClauseTellsTheTwoProblemsApart()

	/**
	 * A case is identified by whichever key the store used.
	 *
	 * @return void
	 */
	public function testACaseIsIdentifiedByEitherKey(): void {
		self::assertSame('c1', $this->resolver->caseId(case: ['id' => 'c1']));
		self::assertSame('c2', $this->resolver->caseId(case: ['uuid' => 'c2']));
		self::assertSame('', $this->resolver->caseId(case: []));
		self::assertSame('c3', $this->resolver->caseId(case: ['id' => ['id' => 'c3']]));
	}//end testACaseIsIdentifiedByEitherKey()

	/**
	 * 🔴 A reference reads the same whether the store expanded it or not.
	 *
	 * This is the shape that bit: a `$ref` arrives as a uuid string on a plain
	 * read and as the expanded object when the caller asked for it, and a
	 * `(string)` cast on the expanded form yields the literal "Array" with a
	 * warning this suite does not fail on.
	 *
	 * @return void
	 */
	public function testAReferenceReadsTheSameExpandedOrNot(): void {
		self::assertSame('rol-7', $this->resolver->referenceId(value: 'rol-7'));
		self::assertSame('rol-7', $this->resolver->referenceId(value: ['id' => 'rol-7']));
		self::assertSame('rol-7', $this->resolver->referenceId(value: ['uuid' => 'rol-7']));
		self::assertSame('rol-7', $this->resolver->referenceId(value: '  rol-7  '));
	}//end testAReferenceReadsTheSameExpandedOrNot()

	/**
	 * A reference that names nothing is empty, never the word "Array".
	 *
	 * @return void
	 */
	public function testAReferenceNamingNothingIsEmpty(): void {
		foreach ([null, '', [], ['name' => 'Vergunningen'], false] as $value) {
			$id = $this->resolver->referenceId(value: $value);
			self::assertSame('', $id, 'a reference naming nothing must be empty');
			self::assertNotSame('Array', $id);
		}
	}//end testAReferenceNamingNothingIsEmpty()
}//end class
