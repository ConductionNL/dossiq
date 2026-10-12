<?php

/**
 * Tests for TriageRuleController, through the real rules over an in-memory register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\TriageRuleController;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Review\TriageConditions;
use OCA\Dossiq\Review\TriageFacts;
use OCA\Dossiq\Review\TriageRules;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\TriageRuleController
 * @uses   \OCA\Dossiq\Review\TriageRules
 * @uses   \OCA\Dossiq\Review\TriageConditions
 * @uses   \OCA\Dossiq\Review\TriageFacts
 * @uses   \OCA\Dossiq\Review\DocumentRelevance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class TriageRuleControllerTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Three mails on case X, two from the newsletter domain.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		foreach (['mail-1' => 'nieuwsbrief.example.nl', 'mail-2' => 'nieuwsbrief.example.nl', 'mail-3' => 'gemeente.nl'] as $id => $domain) {
			$this->store->seed(schema: 'informatieobject', uuid: $id, row: ['title' => $id, 'provenance' => ['source' => 'files', 'mail' => ['from' => 'info@'.$domain]]]);
			$this->store->seed(schema: 'zaakinformatieobject', uuid: 'join-'.$id, row: ['case' => 'case-x', 'informatieobject' => $id]);
		}
	}//end setUp()

	/**
	 * The controller with the given access and request parameters.
	 *
	 * @param bool                 $read   Read access to the case.
	 * @param bool                 $mutate Mutation access to the case.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return TriageRuleController
	 */
	private function controller(bool $read = true, bool $mutate = true, array $params = []): TriageRuleController {
		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview', 'triage_rule_schema' => 'triageRule', 'dossier_informatieobject_schema' => 'informatieobject', 'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($read);
		$guard->method('hasCaseMutationAccess')->willReturn($mutate);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// Every record of the case regardless of the caller, as CaseRecordLookup reads it.
		$access = $this->createMock(CaseReviewerAccess::class);
		$access->method('recordsOf')->willReturnCallback(
			fn (string $schemaKey, string $caseField, string $caseId): array => array_values(
				array_filter($this->store->all(schema: 'triageRule'), static fn (array $row): bool => ($row[$caseField] ?? '') === $caseId)
			)
		);

		return new TriageRuleController(
			request: $request,
			rules: new TriageRules(
				settingsService: $settings,
				reviews: new DocumentRelevance(settingsService: $settings, logger: $logger),
				caseDocuments: $caseDocuments,
				facts: new TriageFacts(
					caseDocuments: $caseDocuments,
					provenance: new CaseDocumentProvenance(store: new DocumentRecordStore(settingsService: $settings), caseDocuments: $caseDocuments),
					text: $this->createMock(DocumentText::class),
					logger: $logger,
				),
				conditions: new TriageConditions(),
				reviewerAccess: $access,
				logger: $logger,
			),
			caseAccessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * Apply answers the count per rule, and the list shows what each rule decided.
	 *
	 * @return void
	 */
	public function testApplyAnswersTheCountPerRule(): void {
		$created = $this->controller(params: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope'])->create(id: 'case-x');
		$this->assertSame(201, $created->getStatus());

		$applied = $this->controller()->apply(id: 'case-x');

		$this->assertSame(200, $applied->getStatus());
		$this->assertSame(['counts' => ['Nieuwsbrieven' => 2], 'marked' => 2, 'considered' => 3], $applied->getData());
		$listed = $this->controller()->index(id: 'case-x')->getData()['results'];
		$this->assertSame(2, $listed[0]['decided']);
	}//end testApplyAnswersTheCountPerRule()

	/**
	 * An incomplete rule answers 422 with the rule and a sentence; an unknown rule 404.
	 *
	 * @return void
	 */
	public function testAnIncompleteRuleAnswersTheRefusal(): void {
		$refused = $this->controller(params: ['name' => 'Zonder effect', 'conditions' => ['custodian' => 'A. de Wit']])->create(id: 'case-x');

		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('triage-rule-effect-required', $refused->getData()['error']);
		$this->assertNotSame('', $refused->getData()['message']);
		$this->assertSame(404, $this->controller(params: ['active' => 'false'])->update(id: 'case-x', ruleId: 'nope')->getStatus());
		$this->assertSame(404, $this->controller()->destroy(id: 'case-x', ruleId: 'nope')->getStatus());
	}//end testAnIncompleteRuleAnswersTheRefusal()

	/**
	 * A rule is switched off by a change and removed by a delete.
	 *
	 * @return void
	 */
	public function testARuleIsSwitchedOffAndDeleted(): void {
		$id = (string)$this->controller(params: ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope'])->create(id: 'case-x')->getData()['id'];

		$off = $this->controller(params: ['active' => 'false'])->update(id: 'case-x', ruleId: $id);
		$this->assertFalse($off->getData()['active']);
		$this->assertSame(0, $this->controller()->apply(id: 'case-x')->getData()['marked']);

		$this->assertSame(204, $this->controller()->destroy(id: 'case-x', ruleId: $id)->getStatus());
		$this->assertSame([], $this->controller()->index(id: 'case-x')->getData()['results']);
	}//end testARuleIsSwitchedOffAndDeleted()

	/**
	 * A user without a grant on the case is refused: reading needs read access, the rest mutation access.
	 *
	 * @return void
	 */
	public function testAUserWithoutAGrantOnTheCaseIsRefused(): void {
		$writes = $this->store->writes;
		$params = ['name' => 'Nieuwsbrieven', 'conditions' => ['senderDomain' => 'nieuwsbrief.example.nl'], 'effect' => 'out-of-scope'];

		$this->assertSame(403, $this->controller(read: false, mutate: false)->index(id: 'case-x')->getStatus());
		$this->assertSame(403, $this->controller(read: true, mutate: false, params: $params)->create(id: 'case-x')->getStatus());
		$this->assertSame(403, $this->controller(read: true, mutate: false)->apply(id: 'case-x')->getStatus());
		$this->assertSame(403, $this->controller(read: true, mutate: false)->update(id: 'case-x', ruleId: 'r')->getStatus());
		$this->assertSame(403, $this->controller(read: true, mutate: false)->destroy(id: 'case-x', ruleId: 'r')->getStatus());
		$this->assertSame($writes, $this->store->writes);
	}//end testAUserWithoutAGrantOnTheCaseIsRefused()
}//end class
