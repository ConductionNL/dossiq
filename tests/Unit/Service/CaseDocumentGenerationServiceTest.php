<?php

/**
 * Unit tests for CaseDocumentGenerationService.
 *
 * The Generate document button asks Filinq for the document by dispatching
 * the real DocumentGenerationRequestedEvent, and files what Filinq stored.
 * What is worth protecting: the request carries the template in Filinq's
 * syntax, the case as its object and the metadata the dossier needs; a
 * template the case cannot fill refuses before anything is asked; and an
 * instance where nobody answers the event says Filinq is required instead of
 * reporting a document that does not exist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DocumentTemplating;
use OCA\Dossiq\Service\CaseDocumentGenerationService;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TemplateLibraryService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Filinq\Event\DocumentGenerationRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDocumentGenerationService
 * @covers \OCA\Dossiq\Service\DocumentTemplating
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 * @uses \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 */
class CaseDocumentGenerationServiceTest extends TestCase {

	/**
	 * The request event the service dispatched, or null.
	 *
	 * @var DocumentGenerationRequestedEvent|null
	 */
	private ?DocumentGenerationRequestedEvent $request = null;

	/**
	 * What the filer was asked to file, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $filed = null;

	/**
	 * Whether filing the generated file fails.
	 *
	 * @var boolean
	 */
	private bool $filerFails = false;

	/**
	 * A service over stubbed collaborators.
	 *
	 * @param array<string, mixed>|null $template The template the library holds.
	 * @param array<string, mixed>|null $case     The case the store holds.
	 * @param string                    $filinq   How "Filinq" answers: `ok`, `error` or `absent`.
	 *
	 * @return CaseDocumentGenerationService The service under test.
	 */
	private function service(?array $template, ?array $case, string $filinq = 'ok'): CaseDocumentGenerationService {
		$templates = $this->createMock(TemplateLibraryService::class);
		$templates->method('loadTemplate')->willReturn($template);

		$cases = $this->createMock(CaseStatusStore::class);
		$cases->method('loadCase')->willReturn($case);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => '12', 'case_schema' => '34'][$key] ?? $default)
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($filinq): void {
				self::assertInstanceOf(DocumentGenerationRequestedEvent::class, $event);
				$this->request = $event;
				if ($filinq === 'ok') {
					$event->setResult(['fileId' => 77, 'mime' => 'application/pdf', 'name' => 'Ontvangstbevestiging.pdf']);
				}

				if ($filinq === 'error') {
					$event->setError('Template rendering failed');
				}

				if ($filinq === 'nofile') {
					$event->setResult(['fileId' => 0]);
				}
			}
		);

		$filer = $this->createMock(GeneratedDocumentFiler::class);
		$filer->method('file')->willReturnCallback(
			function (string $caseId, int $fileId, string $title, string $mime, array $metadata): array {
				if ($this->filerFails === true) {
					throw new \RuntimeException('disk full');
				}

				$this->filed = ['caseId' => $caseId, 'fileId' => $fileId, 'title' => $title, 'mime' => $mime, 'metadata' => $metadata];

				return ['id' => 'inf-1'];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseDocumentGenerationService(
			cases: $cases,
			templating: new DocumentTemplating(templates: $templates, filer: $filer, syntax: new RetiredTemplateSyntax()),
			settingsService: $settings,
			events: $events,
			l10n: $l10n,
		);
	}//end service()

	/**
	 * A library template.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(): array {
		return ['title' => 'Ontvangstbevestiging', 'documentType' => 'type-uuid', 'body' => 'Beste {{case.title}}, wij ontvingen uw aanvraag.'];
	}//end template()

	/**
	 * The whole request Filinq is asked, and the whole filing that follows.
	 *
	 * @return void
	 */
	public function testTheRequestAndTheFilingCarryEveryField(): void {
		$this->service($this->template(), ['id' => 'case-1', 'title' => 'Dakkapel'])->generate('case-1', 'tpl');

		$metadata = [GeneratedDocumentFiler::TYPE => 'type-uuid', 'direction' => 'outgoing', 'addressees' => 'case', GeneratedDocumentFiler::FILED_BY_CALLER => true];
		self::assertSame(
			[
				'template' => 'Beste {{ item.title }}, wij ontvingen uw aanvraag.',
				'templateName' => 'Ontvangstbevestiging',
				'filename' => 'Ontvangstbevestiging',
				'format' => 'pdf',
				'storeFile' => true,
				'data' => ['id' => 'case-1', 'title' => 'Dakkapel'],
				'object' => ['register' => '12', 'schema' => '34', 'id' => 'case-1'],
				'metadata' => $metadata,
			],
			$this->request->getRequest()
		);
		self::assertSame(['caseId' => 'case-1', 'fileId' => 77, 'title' => 'Ontvangstbevestiging', 'mime' => 'application/pdf', 'metadata' => $metadata], $this->filed);
	}//end testTheRequestAndTheFilingCarryEveryField()

	/**
	 * Every refusal of a generation, with its exact error.
	 *
	 * @return void
	 */
	public function testEveryRefusalCarriesItsError(): void {
		$case = ['id' => 'case-1', 'title' => 'X'];
		$errors = [
			$this->service(null, $case)->generate('case-1', 'tpl')->error,
			$this->service(['body' => '  '] + $this->template(), $case)->generate('case-1', 'tpl')->error,
			$this->service($this->template(), null)->generate('case-1', 'tpl')->error,
			$this->service(['documentType' => ' '] + $this->template(), $case)->generate('case-1', 'tpl')->error,
			$this->service($this->template(), $case, filinq: 'nofile')->generate('case-1', 'tpl')->error,
		];
		$this->filerFails = true;
		$errors[] = $this->service($this->template(), $case)->generate('case-1', 'tpl')->error;

		self::assertSame(
			['template_not_found', 'template_has_no_body', 'case_not_found', 'missing_document_type', 'document_generation_failed: Filinq stored no file', 'document_not_filed: disk full'],
			$errors
		);
	}//end testEveryRefusalCarriesItsError()

	/**
	 * A template without a title is named after its id.
	 *
	 * @return void
	 */
	public function testATemplateWithoutATitleIsNamedAfterItsId(): void {
		$this->service(['title' => ' '] + $this->template(), ['id' => 'case-1', 'title' => 'X'])->generate('case-1', 'tpl-9');

		self::assertSame('tpl-9', $this->request->getRequest()['templateName']);
		self::assertSame('tpl-9', $this->filed['title']);
	}//end testATemplateWithoutATitleIsNamedAfterItsId()

	/**
	 * The button asks Filinq, files the result, and answers with the informatieobject.
	 *
	 * @return void
	 */
	public function testTheDocumentIsRequestedFromFilinqAndFiledOnTheCase(): void {
		$result = $this->service($this->template(), ['id' => 'case-1', 'title' => 'Dakkapel'])->generate('case-1', 'tpl');

		self::assertTrue($result->succeeded, (string)$result->error);
		self::assertSame(['informatieobject' => 'inf-1', 'case' => 'case-1'], $result->data);

		self::assertNotNull($this->request);
		self::assertSame('dossiq', $this->request->getRequestingApp());
		$request = $this->request->getRequest();
		self::assertSame('Beste {{ item.title }}, wij ontvingen uw aanvraag.', $request['template']);
		self::assertTrue($request['storeFile']);
		self::assertSame(['register' => '12', 'schema' => '34', 'id' => 'case-1'], $request['object']);
		self::assertTrue($request['metadata'][GeneratedDocumentFiler::FILED_BY_CALLER]);
		self::assertSame('type-uuid', $request['metadata']['informatieobjecttype']);

		self::assertSame(77, $this->filed['fileId']);
		self::assertSame('case-1', $this->filed['caseId']);
		self::assertSame('Ontvangstbevestiging', $this->filed['title']);
	}//end testTheDocumentIsRequestedFromFilinqAndFiledOnTheCase()

	/**
	 * Nobody answers the event: the refusal names Filinq, and nothing is filed.
	 *
	 * @return void
	 */
	public function testWithoutFilinqTheButtonSaysFilinqIsRequired(): void {
		$result = $this->service($this->template(), ['id' => 'case-1', 'title' => 'X'], filinq: 'absent')->generate('case-1', 'tpl');

		self::assertFalse($result->succeeded);
		self::assertStringContainsString('Filinq', (string)$result->error);
		self::assertNull($this->filed);
	}//end testWithoutFilinqTheButtonSaysFilinqIsRequired()

	/**
	 * Filinq refused: its reason is passed on, and nothing is filed.
	 *
	 * @return void
	 */
	public function testFilinqsErrorIsPassedOn(): void {
		$result = $this->service($this->template(), ['id' => 'case-1', 'title' => 'X'], filinq: 'error')->generate('case-1', 'tpl');

		self::assertFalse($result->succeeded);
		self::assertSame('document_generation_failed: Template rendering failed', $result->error);
		self::assertNull($this->filed);
	}//end testFilinqsErrorIsPassedOn()

	/**
	 * A placeholder the case cannot fill refuses before Filinq is asked.
	 *
	 * @return void
	 */
	public function testAHoleInTheLetterRefusesBeforeAnythingIsAsked(): void {
		$result = $this->service($this->template(), ['id' => 'case-1'])->generate('case-1', 'tpl');

		self::assertFalse($result->succeeded);
		self::assertSame('missing_template_field:case.title', $result->error);
		self::assertNull($this->request);
	}//end testAHoleInTheLetterRefusesBeforeAnythingIsAsked()

	/**
	 * An unknown template or case generates nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateOrCaseGeneratesNothing(): void {
		self::assertSame('template_not_found', $this->service(null, ['id' => 'c'])->generate('c', 'tpl')->error);
		self::assertSame('case_not_found', $this->service($this->template(), null)->generate('c', 'tpl')->error);
		self::assertSame('template_has_no_body', $this->service(['body' => ' '], ['id' => 'c'])->generate('c', 'tpl')->error);
		self::assertNull($this->request);
	}//end testAnUnknownTemplateOrCaseGeneratesNothing()

	/**
	 * A case read without its id still files against the requested case.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdStillFilesAgainstTheRequestedCase(): void {
		$this->service($this->template(), ['title' => 'X'])->generate('case-9', 'tpl');

		self::assertSame('case-9', $this->filed['caseId']);
		self::assertSame('case-9', $this->request->getRequest()['data']['id']);
	}//end testACaseWithoutAnIdStillFilesAgainstTheRequestedCase()
}//end class
