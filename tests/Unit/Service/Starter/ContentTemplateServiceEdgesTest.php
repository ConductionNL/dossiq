<?php

/**
 * The template library's edges: no store, an unknown template, scope and mail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\EmailTemplateService;
use OCA\Dossiq\Service\Starter\ContentTemplateService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ContentTemplateService's refusals and the mail branch.
 */
#[CoversClass(ContentTemplateService::class)]
#[UsesClass(StarterStore::class)]
#[UsesClass(LanguageMapText::class)]
#[UsesClass(TranslatedText::class)]
final class ContentTemplateServiceEdgesTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The mail store.
	 *
	 * @var EmailTemplateService&MockObject
	 */
	private EmailTemplateService $mail;

	/**
	 * Build the library over a real in-memory store and a mocked mail store.
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
	}//end setUp()

	/**
	 * The library over the given logger.
	 *
	 * @param LoggerInterface|null $logger The logger; a null logger when not given.
	 *
	 * @return ContentTemplateService
	 */
	private function library(?LoggerInterface $logger = null): ContentTemplateService {
		return new ContentTemplateService($this->harness->store, $this->mail, ($logger ?? new NullLogger()));
	}//end library()

	/**
	 * An unknown kind is offered nothing, without asking any store.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsOfferedNothing(): void {
		$this->mail->expects($this->never())->method('listTemplates');

		self::assertSame(expected: [], actual: $this->library()->offered(kind: 'letter', caseTypeId: 'ct-1'));
	}//end testAnUnknownKindIsOfferedNothing()

	/**
	 * With the templates schema unconfigured, the library answers null, not an empty list.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreAnswersNull(): void {
		$this->harness = new StarterStoreHarness(test: $this, unconfigured: [ContentTemplateService::TEMPLATES]);

		self::assertNull(actual: $this->library()->offered(kind: ContentTemplateService::KIND_TASK, caseTypeId: 'ct-1'));
	}//end testAnUnconfiguredStoreAnswersNull()

	/**
	 * Applying a template that is not there answers null.
	 *
	 * @return void
	 */
	public function testApplyingAnUnknownTemplateAnswersNull(): void {
		self::assertNull(actual: $this->library()->apply(templateId: 'tpl-missing'));
	}//end testApplyingAnUnknownTemplateAnswersNull()

	/**
	 * Applying a template with presets that are not a map and no body answers an empty preset.
	 *
	 * @return void
	 */
	public function testPresetsThatAreNotAMapApplyAsNone(): void {
		$this->harness->seed(
			schema: 'contentTemplate',
			uuid: 'tpl-odd',
			row: ['id' => 'tpl-odd', 'kind' => 'task', 'name' => 'Odd', 'presets' => 'title=Odd'],
		);

		self::assertSame(expected: [], actual: $this->library()->apply(templateId: 'tpl-odd'));

		$offered = $this->library()->offered(kind: ContentTemplateService::KIND_TASK, caseTypeId: '');
		self::assertCount(expectedCount: 1, haystack: $offered);
		self::assertSame(expected: [], actual: $offered[0]['presets']);
	}//end testPresetsThatAreNotAMapApplyAsNone()

	/**
	 * A template scoped to case types is not offered where no case type is known,
	 * nor on another case type, and is offered on its own.
	 *
	 * @return void
	 */
	public function testAScopedTemplateNeedsItsCaseType(): void {
		$this->harness->seed(
			schema: 'contentTemplate',
			uuid: 'tpl-note',
			row: ['id' => 'tpl-note', 'kind' => 'note', 'name' => 'Telefonisch contact', 'caseTypes' => ['ct-a', 'ct-b']],
		);
		$library = $this->library();

		self::assertSame(expected: [], actual: $library->offered(kind: ContentTemplateService::KIND_NOTE, caseTypeId: ''));
		self::assertSame(expected: [], actual: $library->offered(kind: ContentTemplateService::KIND_NOTE, caseTypeId: 'ct-c'));

		$offered = $library->offered(kind: ContentTemplateService::KIND_NOTE, caseTypeId: 'ct-b');
		self::assertSame(expected: 'tpl-note', actual: $offered[0]['id']);
		self::assertSame(expected: 'Telefonisch contact', actual: $offered[0]['name']);
	}//end testAScopedTemplateNeedsItsCaseType()

	/**
	 * A search narrows the stored templates case-insensitively by name.
	 *
	 * @return void
	 */
	public function testASearchNarrowsTheTemplatesByName(): void {
		foreach (['tpl-a' => 'Advies juridische zaken', 'tpl-b' => 'Bel de aanvrager'] as $id => $name) {
			$this->harness->seed(schema: 'contentTemplate', uuid: $id, row: ['id' => $id, 'kind' => 'task', 'name' => $name]);
		}

		$offered = $this->library()->offered(kind: ContentTemplateService::KIND_TASK, caseTypeId: '', search: 'JURIDISCH');

		self::assertSame(expected: ['tpl-a'], actual: array_column($offered, 'id'));
	}//end testASearchNarrowsTheTemplatesByName()

	/**
	 * Mail templates come from the mail store, shaped as library entries, narrowed by the search.
	 *
	 * @return void
	 */
	public function testMailTemplatesComeFromTheMailStoreNarrowedBySearch(): void {
		$this->mail->expects($this->once())->method('listTemplates')->with('ct-1')->willReturn(
			[
				['id' => 'm-1', 'name' => 'Ontvangstbevestiging', 'subject' => 'Wij hebben uw aanvraag ontvangen', 'body' => 'Beste,'],
				['id' => 'm-2', 'name' => 'Besluit verzonden'],
			]
		);

		$offered = $this->library()->offered(kind: ContentTemplateService::KIND_MAIL, caseTypeId: 'ct-1', search: 'ontvangst');

		self::assertSame(
			expected: [
				[
					'id' => 'm-1',
					'kind' => ContentTemplateService::KIND_MAIL,
					'name' => 'Ontvangstbevestiging',
					'body' => 'Beste,',
					'presets' => ['subject' => 'Wij hebben uw aanvraag ontvangen'],
				],
			],
			actual: $offered
		);
	}//end testMailTemplatesComeFromTheMailStoreNarrowedBySearch()

	/**
	 * A failing mail store is logged and answered null.
	 *
	 * @return void
	 */
	public function testAFailingMailStoreIsLoggedAndAnsweredNull(): void {
		$this->mail->method('listTemplates')->willThrowException(new RuntimeException('db gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq starter: could not list mail templates',
			['caseType' => 'ct-1', 'exception' => 'db gone']
		);

		self::assertNull(actual: $this->library(logger: $logger)->offered(kind: ContentTemplateService::KIND_MAIL, caseTypeId: 'ct-1'));
	}//end testAFailingMailStoreIsLoggedAndAnsweredNull()
}//end class
