<?php

/**
 * Unit tests for CaseDocumentGenerationService.
 *
 * The service is the seam between the Generate document button and
 * MergeTemplateHandler's no-targetField branch, so what is worth protecting is
 * the SHAPE of the config it hands over: a template body, the template's name
 * as the title, its document type resolved to a catalogue row, and no
 * `targetField` at all. A `targetField` slipping in here would write the
 * letter into a case field and file nothing, which looks like success.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCA\Dossiq\Service\CaseDocumentGenerationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TemplateLibraryService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDocumentGenerationService
 *
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class CaseDocumentGenerationServiceTest extends TestCase {

	/**
	 * The action config the handler double was handed, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $dispatched = null;

	/**
	 * The case the handler double was handed, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $dispatchedCase = null;

	/**
	 * Reset the recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatched = null;
		$this->dispatchedCase = null;
	}//end setUp()

	/**
	 * A service over stubbed collaborators.
	 *
	 * @param array<string, mixed>|null $template The template the library holds.
	 * @param array<string, mixed>|null $case The case the store holds.
	 * @param array<int, array<string, mixed>> $types The catalogue rows.
	 *
	 * @return CaseDocumentGenerationService The service under test.
	 */
	private function service(?array $template, ?array $case, array $types = []): CaseDocumentGenerationService {
		$templates = $this->createMock(TemplateLibraryService::class);
		$templates->method('loadTemplate')->willReturn($template);

		$cases = $this->createMock(CaseStatusStore::class);
		$cases->method('loadCase')->willReturn($case);

		$objectService = new class($types) {
			/**
			 * @param array<int, array<string, mixed>> $rows The catalogue rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				unset($register, $schema, $filters);

				return $this->rows;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
				];

				return ($map[$key] ?? $default);
			}
		);

		$handler = $this->createMock(MergeTemplateHandler::class);
		$handler->method('handle')->willReturnCallback(
			function (array $actionConfig, array $caseArg, array $context): ActionResult {
				unset($context);
				$this->dispatched = $actionConfig;
				$this->dispatchedCase = $caseArg;

				return new ActionResult(succeeded: true, data: ['informatieobject' => 'inf-1']);
			}
		);

		return new CaseDocumentGenerationService(
			cases: $cases,
			templates: $templates,
			settingsService: $settings,
			handler: $handler,
		);
	}//end service()

	/**
	 * The handler is asked to file, not to write a case field.
	 *
	 * @return void
	 */
	public function testTheHandlerIsCalledWithoutATargetField(): void {
		$result = $this->service(
			['id' => 'ontvangstbevestiging', 'title' => 'Ontvangstbevestiging', 'body' => 'Beste lezer'],
			['id' => 'case-1', 'title' => 'Kapvergunning'],
		)->generate(caseId: 'case-1', templateId: 'ontvangstbevestiging');

		$this->assertTrue($result->succeeded);
		$this->assertArrayNotHasKey('targetField', $this->dispatched);
		$this->assertSame('Beste lezer', $this->dispatched['template']);
		$this->assertSame('Ontvangstbevestiging', $this->dispatched['templateName']);
		$this->assertSame('case-1', $this->dispatchedCase['id']);
	}//end testTheHandlerIsCalledWithoutATargetField()

	/**
	 * REQ-005: a template's documentType resolves to the catalogue row's id.
	 *
	 * @return void
	 */
	public function testTheDocumentTypeResolvesToTheCatalogueRow(): void {
		$this->service(
			[
				'id' => 'ontvangstbevestiging',
				'title' => 'Ontvangstbevestiging',
				'body' => 'Beste lezer',
				'documentType' => 'Ontvangstbevestiging',
			],
			['id' => 'case-1'],
			[['id' => 'iot-7', 'description' => 'Ontvangstbevestiging']],
		)->generate(caseId: 'case-1', templateId: 'ontvangstbevestiging');

		$this->assertSame('iot-7', $this->dispatched['documentType']);
	}//end testTheDocumentTypeResolvesToTheCatalogueRow()

	/**
	 * A type the catalogue does not hold is passed through by name.
	 *
	 * The Documents tab renders an unresolved type as its raw value, so the
	 * column reads Ontvangstbevestiging rather than going blank.
	 *
	 * @return void
	 */
	public function testAnUnknownDocumentTypeIsPassedThroughByName(): void {
		$this->service(
			[
				'id' => 'verdagingsbrief',
				'title' => 'Verdagingsbrief',
				'body' => 'Beste lezer',
				'documentType' => 'Verdagingsbrief',
			],
			['id' => 'case-1'],
			[],
		)->generate(caseId: 'case-1', templateId: 'verdagingsbrief');

		$this->assertSame('Verdagingsbrief', $this->dispatched['documentType']);
	}//end testAnUnknownDocumentTypeIsPassedThroughByName()

	/**
	 * A template the library does not hold generates nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateGeneratesNothing(): void {
		$result = $this->service(null, ['id' => 'case-1'])
			->generate(caseId: 'case-1', templateId: 'nope');

		$this->assertFalse($result->succeeded);
		$this->assertSame('template_not_found', $result->error);
		$this->assertNull($this->dispatched);
	}//end testAnUnknownTemplateGeneratesNothing()

	/**
	 * A zaaktype bundle has no body, so it cannot be rendered as a letter.
	 *
	 * The library holds both kinds — the picker lists everything it returns —
	 * so picking a case-type bundle must say so rather than file an empty
	 * document.
	 *
	 * @return void
	 */
	public function testATemplateWithNoBodyGeneratesNothing(): void {
		$result = $this->service(
			['id' => 'omgevingsvergunning', 'title' => 'Omgevingsvergunning', 'caseType' => []],
			['id' => 'case-1'],
		)->generate(caseId: 'case-1', templateId: 'omgevingsvergunning');

		$this->assertFalse($result->succeeded);
		$this->assertSame('template_has_no_body', $result->error);
		$this->assertNull($this->dispatched);
	}//end testATemplateWithNoBodyGeneratesNothing()

	/**
	 * A case that cannot be read generates nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseGeneratesNothing(): void {
		$result = $this->service(
			['id' => 'ontvangstbevestiging', 'title' => 'Ontvangstbevestiging', 'body' => 'Beste lezer'],
			null,
		)->generate(caseId: 'case-1', templateId: 'ontvangstbevestiging');

		$this->assertFalse($result->succeeded);
		$this->assertSame('case_not_found', $result->error);
		$this->assertNull($this->dispatched);
	}//end testAnUnreadableCaseGeneratesNothing()

	/**
	 * A case read without its own id still files against the requested case.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdStillFilesAgainstTheRequestedCase(): void {
		$this->service(
			['id' => 'ontvangstbevestiging', 'title' => 'Ontvangstbevestiging', 'body' => 'Beste lezer'],
			['title' => 'Kapvergunning'],
		)->generate(caseId: 'case-9', templateId: 'ontvangstbevestiging');

		$this->assertSame('case-9', $this->dispatchedCase['id']);
	}//end testACaseWithoutAnIdStillFilesAgainstTheRequestedCase()
}//end class
