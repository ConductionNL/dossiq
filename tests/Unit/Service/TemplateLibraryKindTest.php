<?php

/**
 * One library, six kinds, offered where each kind is created.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\EmailTemplateService;
use OCA\Dossiq\Service\Starter\ContentTemplateService;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ContentTemplateService.
 *
 * @covers \OCA\Dossiq\Service\Starter\ContentTemplateService
 *
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 */
class TemplateLibraryKindTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The mail store, which keeps its own records.
	 *
	 * @var EmailTemplateService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private EmailTemplateService $mail;

	/**
	 * The service under test.
	 *
	 * @var ContentTemplateService
	 */
	private ContentTemplateService $library;

	/**
	 * A task, a note, a result and a mail template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);

		$this->mail = $this->getMockBuilder(EmailTemplateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['listTemplates'])
			->getMock();

		$this->library = new ContentTemplateService($this->harness->store, $this->mail, new NullLogger());

		$this->harness->seed(
			schema: 'contentTemplate',
			uuid: 'tpl-task',
			row: [
				'id' => 'tpl-task',
				'kind' => 'task',
				'name' => 'Vraag advies aan juridische zaken',
				'caseTypes' => [],
				'presets' => ['title' => 'Vraag advies aan juridische zaken', 'group' => 'jz', 'leadTimeDays' => 10],
			],
		);
		$this->harness->seed(
			schema: 'contentTemplate',
			uuid: 'tpl-note',
			row: ['id' => 'tpl-note', 'kind' => 'note', 'name' => 'Telefonisch contact', 'caseTypes' => ['ct-bezwaar']],
		);
		$this->harness->seed(
			schema: 'contentTemplate',
			uuid: 'tpl-result',
			row: [
				'id' => 'tpl-result',
				'kind' => 'result',
				'name' => 'Niet-ontvankelijkverklaring',
				'caseTypes' => [],
				'body' => 'Uw bezwaar is niet-ontvankelijk verklaard.',
			],
		);
	}//end setUp()

	/**
	 * A task template is offered where a task is added, and it presets the
	 * title, the group and the lead time.
	 *
	 * @return void
	 */
	public function testATaskTemplateIsOfferedAndPresetsTheTask(): void {
		$offered = $this->library->offered(kind: 'task', caseTypeId: 'ct-vth');

		self::assertNotNull(actual: $offered);
		self::assertCount(expectedCount: 1, haystack: $offered);
		self::assertSame(expected: 'Vraag advies aan juridische zaken', actual: $offered[0]['name']);

		$presets = $this->library->apply(templateId: 'tpl-task');

		self::assertNotNull(actual: $presets);
		self::assertSame(expected: 'Vraag advies aan juridische zaken', actual: $presets['title']);
		self::assertSame(expected: 'jz', actual: $presets['group']);
		self::assertSame(expected: 10, actual: $presets['leadTimeDays']);
	}//end testATaskTemplateIsOfferedAndPresetsTheTask()

	/**
	 * A result template presets the outcome text.
	 *
	 * @return void
	 */
	public function testAResultTemplatePresetsTheOutcomeText(): void {
		$presets = $this->library->apply(templateId: 'tpl-result');

		self::assertNotNull(actual: $presets);
		self::assertSame(
			expected: 'Uw bezwaar is niet-ontvankelijk verklaard.',
			actual: $presets['body']
		);
	}//end testAResultTemplatePresetsTheOutcomeText()

	/**
	 * A template scoped to bezwaar is not offered on a vergunning case.
	 *
	 * @return void
	 */
	public function testATemplateIsScopedToItsCaseTypes(): void {
		self::assertSame(
			expected: [],
			actual: $this->library->offered(kind: 'note', caseTypeId: 'ct-vergunning')
		);
		self::assertCount(
			expectedCount: 1,
			haystack: (array)$this->library->offered(kind: 'note', caseTypeId: 'ct-bezwaar')
		);
	}//end testATemplateIsScopedToItsCaseTypes()

	/**
	 * A template scoped to nothing is offered everywhere.
	 *
	 * @return void
	 */
	public function testATemplateScopedToNothingIsOfferedEverywhere(): void {
		self::assertCount(
			expectedCount: 1,
			haystack: (array)$this->library->offered(kind: 'result', caseTypeId: 'ct-anything')
		);
	}//end testATemplateScopedToNothingIsOfferedEverywhere()

	/**
	 * Templates are searchable by name.
	 *
	 * @return void
	 */
	public function testTemplatesAreSearchableByName(): void {
		self::assertCount(
			expectedCount: 1,
			haystack: (array)$this->library->offered(kind: 'task', caseTypeId: 'ct-vth', search: 'juridische')
		);
		self::assertSame(
			expected: [],
			actual: $this->library->offered(kind: 'task', caseTypeId: 'ct-vth', search: 'hoorzitting')
		);
	}//end testTemplatesAreSearchableByName()

	/**
	 * Mail is read through the same library, out of its own store.
	 *
	 * 🔑 ONE LIBRARY, TWO STORES, AND THE SEAM IS THE POINT. `emailTemplate`
	 * already holds the mail templates with a copy-on-write version chain the
	 * mail editor reads. Migrating them would have rewritten a working feature
	 * to make a list look tidy; reading both is what makes this one library
	 * rather than two.
	 *
	 * @return void
	 */
	public function testMailIsReadThroughTheSameLibraryOutOfItsOwnStore(): void {
		$this->mail->expects($this->once())
			->method('listTemplates')
			->with('ct-bezwaar')
			->willReturn([
				['id' => 'mail-1', 'name' => 'Ontvangstbevestiging', 'subject' => 'Wij hebben uw bezwaar ontvangen', 'body' => 'Beste'],
			]);

		$offered = $this->library->offered(kind: 'mail', caseTypeId: 'ct-bezwaar');

		self::assertNotNull(actual: $offered);
		self::assertCount(expectedCount: 1, haystack: $offered);
		self::assertSame(expected: 'mail', actual: $offered[0]['kind']);
		self::assertSame(expected: 'Wij hebben uw bezwaar ontvangen', actual: $offered[0]['presets']['subject']);
	}//end testMailIsReadThroughTheSameLibraryOutOfItsOwnStore()

	/**
	 * A kind the library does not hold is answered with nothing, not with
	 * everything.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsOfferedNothing(): void {
		self::assertSame(
			expected: [],
			actual: $this->library->offered(kind: 'invoice', caseTypeId: 'ct-vth')
		);
	}//end testAnUnknownKindIsOfferedNothing()

	/**
	 * Every kind named in the spec is a kind the library holds.
	 *
	 * @return void
	 */
	public function testTheLibraryHoldsEveryKindTheSpecNames(): void {
		foreach (['document', 'mail', 'task', 'note', 'approval', 'result'] as $kind) {
			self::assertContains(needle: $kind, haystack: ContentTemplateService::KINDS);
		}
	}//end testTheLibraryHoldsEveryKindTheSpecNames()
}//end class
