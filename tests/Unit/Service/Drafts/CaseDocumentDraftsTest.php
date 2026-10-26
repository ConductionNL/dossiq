<?php

/**
 * Case Document Drafts Test
 *
 * A document drafted from the template a case type names, asked of filinq
 * through its real DocumentGenerationRequestedEvent (the declaration-only stub
 * the analysers also read) and filed on the case. The Woo case type's real
 * `documentDrafts.decision` declaration is run over a case with one
 * assessment of each classification.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Drafts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Drafts;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\Drafts\CaseDraftData;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Filinq\Event\DocumentGenerationRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Drafts\CaseDocumentDrafts
 * @uses   \OCA\Dossiq\Service\Drafts\CaseDraftData
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseDocumentDraftsTest extends TestCase {

	private const CASE_ID = '66666666-6666-4666-8666-666666666666';

	private const CASE_TYPE = '77777777-7777-4777-8777-777777777777';

	private InMemoryRegister $register;

	/** @var array<string, mixed> */
	private array $caseType = [];

	private ?DocumentGenerationRequestedEvent $request = null;

	private string $filinq = 'ok';

	/** @var list<array<string, mixed>> */
	private array $filed = [];

	private CaseDocumentDrafts $drafts;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'woo_assessment_schema' => 'wooDocumentAssessment',
			][$key] ?? $default
		);
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (string $id): array => $id === self::CASE_TYPE ? $this->caseType : []);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				self::assertInstanceOf(DocumentGenerationRequestedEvent::class, $event);
				$this->request = $event;
				if ($this->filinq === 'ok') {
					$event->setResult(['fileId' => 91, 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'name' => 'Besluit.docx']);
				}

				if ($this->filinq === 'error') {
					$event->setError('template not found: woo-besluit');
				}
			}
		);

		$filer = $this->createMock(GeneratedDocumentFiler::class);
		$filer->method('file')->willReturnCallback(
			function (string $caseId, int $fileId, string $title, string $mime, array $metadata): array {
				$this->filed[] = ['caseId' => $caseId, 'fileId' => $fileId, 'title' => $title, 'metadata' => $metadata];
				return ['id' => 'io-1'];
			}
		);

		$this->drafts = new CaseDocumentDrafts(
			settingsService: $settings,
			caseTypes: new CaseTypeStore(settingsService: $settings),
			resolver: $resolver,
			draftData: new CaseDraftData(),
			events: $events,
			filer: $filer,
		);

		$this->register->seed('case', self::CASE_ID, ['caseType' => self::CASE_TYPE, 'identifier' => 'WOO-2026-0007', 'title' => 'Stationsweg', 'startDate' => '2026-09-01']);
		$this->register->seed('wooDocumentAssessment', 'a-2', ['caseRef' => self::CASE_ID, 'documentRef' => 'doc-b', 'classification' => 'deels_openbaar', 'weigeringsgronden' => ['5.1.2e']]);
		$this->register->seed('wooDocumentAssessment', 'a-1', ['caseRef' => self::CASE_ID, 'documentRef' => 'doc-a', 'classification' => 'openbaar']);
		$this->register->seed('wooDocumentAssessment', 'a-3', ['caseRef' => self::CASE_ID, 'documentRef' => 'doc-c', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.1c']]);
		$this->register->seed('wooDocumentAssessment', 'a-x', ['caseRef' => 'another-case', 'documentRef' => 'doc-x', 'classification' => 'openbaar']);
	}//end setUp()

	/**
	 * The Woo case type's real declaration, read from the shipped fragment.
	 *
	 * @return void
	 */
	private function wooCaseType(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/81-woo-verzoek.json'), true);
		foreach ($fragment['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? '') === 'woo-verzoek') {
				$this->caseType = $object;
			}
		}
	}//end wooCaseType()

	public function testTheWooDecisionIsDraftedFromItsCaseTypesTemplateAndFiled(): void {
		$this->wooCaseType();

		$answer = $this->drafts->draft(caseId: self::CASE_ID, purpose: 'decision');

		self::assertSame(['status' => 'drafted', 'reason' => '', 'informatieobject' => 'io-1', 'fileId' => 91], $answer);
		$request = $this->request?->getRequest() ?? [];
		self::assertSame('woo-besluit', $request['templateSlug']);
		self::assertSame('dossiq', $request['templateNamespace']);
		self::assertSame(['register' => 'dossiq', 'schema' => 'case', 'id' => self::CASE_ID], $request['object']);
		self::assertSame('dossiq', $this->request?->getRequestingApp());

		$decision = $request['data']['wooDecision'];
		self::assertSame('WOO-2026-0007', $decision['reference']);
		self::assertSame('partially-disclose', $decision['decisionKind']);
		self::assertSame('Stationsweg', $decision['subject']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $decision['decisionDate']);
		self::assertSame(
			[
				['documentRef' => 'doc-a', 'assessment' => 'disclose', 'groundCodes' => [], 'inventoryNumber' => 1],
				['documentRef' => 'doc-b', 'assessment' => 'partially-disclose', 'groundCodes' => ['5.1.2e'], 'inventoryNumber' => 2],
				['documentRef' => 'doc-c', 'assessment' => 'withhold', 'groundCodes' => ['5.1.1c'], 'inventoryNumber' => 3],
			],
			$decision['documents'],
			'every assessment of this case and no other, numbered in a stable order'
		);
		self::assertSame(self::CASE_ID, $this->filed[0]['caseId']);
		self::assertSame('draft', $this->filed[0]['metadata']['status']);
	}//end testTheWooDecisionIsDraftedFromItsCaseTypesTemplateAndFiled()

	public function testFilinqsErrorIsTheReasonAndNothingIsFiled(): void {
		$this->wooCaseType();
		$this->filinq = 'error';

		$answer = $this->drafts->draft(caseId: self::CASE_ID, purpose: 'decision');

		self::assertSame('failed', $answer['status']);
		self::assertSame('template not found: woo-besluit', $answer['reason']);
		self::assertSame([], $this->filed);
	}//end testFilinqsErrorIsTheReasonAndNothingIsFiled()

	public function testAnUnhandledEventIsFilinqMissing(): void {
		$this->wooCaseType();
		$this->filinq = 'absent';

		self::assertSame('filinq-missing', $this->drafts->draft(caseId: self::CASE_ID, purpose: 'decision')['status']);
		self::assertSame([], $this->filed);
	}//end testAnUnhandledEventIsFilinqMissing()

	public function testTheInventoryIsDraftedFromItsOwnTemplate(): void {
		$this->wooCaseType();

		self::assertSame('drafted', $this->drafts->draft(caseId: self::CASE_ID, purpose: 'inventory')['status']);
		self::assertSame('woo-inventarislijst', ($this->request?->getRequest() ?? [])['templateSlug']);
	}//end testTheInventoryIsDraftedFromItsOwnTemplate()

	/**
	 * The Woo case type drafts its decision and its inventory; a case type that drafts nothing has none.
	 *
	 * @return void
	 */
	public function testTheDraftedDocumentTypesAreEveryPurposesType(): void {
		$this->wooCaseType();
		$types = $this->drafts->documentTypesForCase(caseId: self::CASE_ID);
		self::assertContains(needle: 'Besluit', haystack: $types);

		$this->caseType = [];
		self::assertSame(expected: [], actual: $this->drafts->documentTypesForCase(caseId: self::CASE_ID));
	}//end testTheDraftedDocumentTypesAreEveryPurposesType()

	public function testAnUndeclaredPurposeAsksNothing(): void {
		$this->wooCaseType();

		self::assertSame('not-declared', $this->drafts->draft(caseId: self::CASE_ID, purpose: 'nonsense')['status']);
		self::assertNull($this->request);
	}//end testAnUndeclaredPurposeAsksNothing()

	public function testDataThatCannotBeBuiltIsRefusedBeforeFilinqIsAsked(): void {
		$this->caseType = ['documentDrafts' => ['letter' => ['templateSlug' => 'letter', 'fields' => [['to' => 'reference', 'from' => 'missing', 'required' => true]]]]];

		$answer = $this->drafts->draft(caseId: self::CASE_ID, purpose: 'letter');

		self::assertSame(['status' => 'refused', 'reason' => 'draft-field-empty:reference', 'informatieobject' => '', 'fileId' => 0], $answer);
		self::assertNull($this->request);
	}//end testDataThatCannotBeBuiltIsRefusedBeforeFilinqIsAsked()

	public function testWithoutADataKeyTheDataSitsBesideTheCase(): void {
		$this->caseType = ['documentDrafts' => ['letter' => ['templateSlug' => 'letter', 'templateNamespace' => 'shared', 'fields' => [['to' => 'reference', 'from' => 'identifier']]]]];

		$this->drafts->draft(caseId: self::CASE_ID, purpose: 'letter');

		$request = $this->request?->getRequest() ?? [];
		self::assertSame('WOO-2026-0007', $request['data']['reference']);
		self::assertSame('Stationsweg', $request['data']['case']['title']);
		self::assertSame('shared', $request['templateNamespace']);
		self::assertSame('pdf', $request['format']);
	}//end testWithoutADataKeyTheDataSitsBesideTheCase()
}//end class
