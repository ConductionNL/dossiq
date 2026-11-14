<?php

/**
 * What a case type declares about which changes make a case unread.
 *
 * THE ASSERTION THAT MATTERS MOST HERE IS THE LAST ONE. OpenRegister resolves
 * `x-openregister-read-state` per SCHEMA, so the block on the `case` schema is
 * the only thing that is actually enforced, and this vocabulary is what the
 * case type is offered. If the two drift, a case type can name a change the
 * evaluator does not watch and the badge is silently dark for it, or the
 * evaluator watches a change no case type can name and the badge lights up for
 * a reason nobody can see. Neither fails anywhere else, so it is asserted here.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\UnreadTriggerService;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * UnreadTriggerService: the declaration, its default and its enforced floor.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
class UnreadTriggerServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var UnreadTriggerService
	 */
	private UnreadTriggerService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new UnreadTriggerService(l10n: new SourceL10n());
	}//end setUp()

	/**
	 * A case type that declares nothing gets the status, the documents and the
	 * messages, which is the default D-2 names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUndeclaredCaseTypeGetsTheDefault(): void {
		$this->assertSame(
			expected: ['status', 'documents', 'messages'],
			actual: $this->service->triggersFor(caseType: ['id' => 'ct'])
		);
		$this->assertSame(
			expected: ['status', 'documents', 'messages'],
			actual: $this->service->triggersFor(caseType: ['id' => 'ct', 'unreadTriggers' => []])
		);
	}//end testAnUndeclaredCaseTypeGetsTheDefault()

	/**
	 * A declaration replaces the default rather than adding to it, and comes
	 * back in vocabulary order however it was typed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testADeclarationReplacesTheDefault(): void {
		$caseType = ['unreadTriggers' => ['deadline', 'status']];

		$this->assertSame(expected: ['status', 'deadline'], actual: $this->service->triggersFor(caseType: $caseType));
		$this->assertTrue(condition: $this->service->declares(caseType: $caseType, trigger: 'status'));
		$this->assertFalse(condition: $this->service->declares(caseType: $caseType, trigger: 'documents'));
	}//end testADeclarationReplacesTheDefault()

	/**
	 * A field the case type does not name is not a reason to mark anything
	 * unread, which is what stops a bulk correction lighting up four hundred
	 * rows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnnamedChangeIsNotNews(): void {
		$caseType = ['unreadTriggers' => ['status']];

		$this->assertFalse(condition: $this->service->declares(caseType: $caseType, trigger: 'assignee'));
		$this->assertFalse(condition: $this->service->declares(caseType: $caseType, trigger: 'deadline'));
		$this->assertSame(expected: ['status'], actual: $this->service->propertiesFor($this->service->triggersFor(caseType: $caseType)));
		$this->assertSame(expected: [], actual: $this->service->subResourcesFor($this->service->triggersFor(caseType: $caseType)));
	}//end testAnUnnamedChangeIsNotNews()

	/**
	 * A name nobody recognises falls back to the default and is reported,
	 * rather than being dropped into a case type that reads as configured and
	 * behaves as if it were not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnknownNameWarnsInsteadOfGoingDark(): void {
		$caseType = ['unreadTriggers' => ['statsu']];

		$this->assertSame(expected: ['status', 'documents', 'messages'], actual: $this->service->triggersFor(caseType: $caseType));

		$warnings = $this->service->publicationWarnings(caseType: $caseType);
		$this->assertCount(expectedCount: 1, haystack: $warnings);
		$this->assertStringContainsString(needle: 'statsu', haystack: $warnings[0]);
	}//end testAnUnknownNameWarnsInsteadOfGoingDark()

	/**
	 * A case type that drops the status says so on publication, because a
	 * handler watching the list will not see it change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testDroppingTheStatusIsWarnedAbout(): void {
		$warnings = $this->service->publicationWarnings(caseType: ['unreadTriggers' => ['documents']]);

		$this->assertCount(expectedCount: 1, haystack: $warnings);
		$this->assertStringContainsString(needle: 'status', haystack: $warnings[0]);

		$this->assertSame(expected: [], actual: $this->service->publicationWarnings(caseType: ['unreadTriggers' => ['status']]));
		$this->assertSame(expected: [], actual: $this->service->publicationWarnings(caseType: []));
	}//end testDroppingTheStatusIsWarnedAbout()

	/**
	 * Documents and messages are not case properties and must never be mapped
	 * onto one: an undeclared property is DROPPED in silence by the store, so
	 * a mapping here would declare a column that does not exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testDocumentsAreASubResourceAndNotAProperty(): void {
		$triggers = ['documents', 'messages'];

		$this->assertSame(expected: [], actual: $this->service->propertiesFor($triggers));
		$this->assertSame(expected: ['files'], actual: $this->service->subResourcesFor($triggers));
	}//end testDocumentsAreASubResourceAndNotAProperty()

	/**
	 * The schema block and this vocabulary say the same thing.
	 *
	 * The block on `case` is the enforced floor, because OpenRegister resolves
	 * the annotation per schema. A trigger a case type can name that the block
	 * does not watch is a badge that silently never lights up; a property the
	 * block watches that no case type can name is a badge that lights up for a
	 * reason nobody can see.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testTheCaseSchemaDeclaresExactlyThisVocabulary(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/37-unread-state.json'),
			true
		);

		$block = $fragment['components']['schemas']['case']['x-openregister-read-state'];

		$this->assertSame(
			expected: $this->service->propertiesFor(UnreadTriggerService::VOCABULARY),
			actual: $block['properties'],
			message: 'the case schema watches exactly the properties the vocabulary maps onto'
		);
		$this->assertSame(
			expected: $this->service->subResourcesFor(UnreadTriggerService::VOCABULARY),
			actual: array_keys($block['subResources']),
			message: 'the case schema badges exactly the sub-resources the vocabulary names'
		);
	}//end testTheCaseSchemaDeclaresExactlyThisVocabulary()

	/**
	 * The case type's own enum offers the whole vocabulary and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testTheCaseTypeEnumIsTheVocabulary(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/37-unread-state.json'),
			true
		);

		$property = $fragment['components']['schemas']['caseType']['properties']['unreadTriggers'];

		$this->assertSame(expected: UnreadTriggerService::VOCABULARY, actual: $property['items']['enum']);
		$this->assertSame(expected: UnreadTriggerService::DEFAULTS, actual: $property['default']);
	}//end testTheCaseTypeEnumIsTheVocabulary()

	/**
	 * The annotation key is reconciled onto the live schema.
	 *
	 * An ABSENT block is not an inert default in OpenRegister: it means every
	 * non-computed property counts. So a key missing from the reconciler does
	 * not switch the badge off, it makes it cry wolf, and nothing anywhere
	 * says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testTheAnnotationKeyIsReconciledOntoTheLiveSchema(): void {
		$this->assertContains(
			needle: 'x-openregister-read-state',
			haystack: \OCA\Dossiq\Service\Settings\SchemaSlugMap::SCHEMA_ANNOTATION_KEYS
		);
	}//end testTheAnnotationKeyIsReconciledOntoTheLiveSchema()

	/**
	 * The warnings come translated, and the unknown names are counted with n().
	 *
	 * @return void
	 */
	public function testTheWarningsAreTranslatedAndCountTheUnknownNames(): void {
		$service  = new UnreadTriggerService(l10n: $this->markingL10n());
		$warnings = $service->publicationWarnings(caseType: ['unreadTriggers' => ['statsu', 'documnets']]);

		$this->assertCount(expectedCount: 1, haystack: $warnings);
		$this->assertStringStartsWith(prefix: 'NL: This case type names 2 changes nobody recognises', string: $warnings[0]);
		$this->assertStringContainsString(needle: 'statsu, documnets', haystack: $warnings[0]);

		$quiet = $service->publicationWarnings(caseType: ['unreadTriggers' => ['documents']]);
		$this->assertStringStartsWith(prefix: 'NL: A case of this type does not read unread', string: $quiet[0]);
	}//end testTheWarningsAreTranslatedAndCountTheUnknownNames()

	/**
	 * A translator that marks what it translated, so a test can tell a
	 * translated warning from a hard-coded one.
	 *
	 * @return IL10N The translator.
	 */
	private function markingL10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'NL: ' . vsprintf($text, $parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => 'NL: ' . vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters)
		);

		return $l10n;
	}//end markingL10n()
}//end class
