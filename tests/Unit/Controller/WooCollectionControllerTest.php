<?php

/**
 * Woo Collection Controller Test
 *
 * The corpus endpoints through the controller over the real plan, collection
 * and query services on one in-memory register: the guards, the refusals and
 * a colleague re-running a query.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WooCollectionController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCollection;
use OCA\Dossiq\Woo\WooCollectionQueries;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCA\Dossiq\Woo\WooSources;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\WooCollectionController
 */
class WooCollectionControllerTest extends TestCase {

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	private InMemoryRegister $register;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	private string $uid = 'pjansen';

	private bool $mayRead = true;

	private bool $mayChange = true;

	/**
	 * The files a user's search finds, by user.
	 *
	 * @var array<string, array<int, File>>
	 */
	private array $files = [];

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
	}//end setUp()

	/**
	 * The controller over the real services.
	 *
	 * @return WooCollectionController The controller.
	 */
	private function controller(): WooCollectionController {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
				'woo_assessment_schema' => 'wooDocumentAssessment',
				'woo_exclusion_schema' => 'wooExclusion',
				'woo_search_plan_schema' => 'wooSearchPlan',
				'woo_request_configuration_schema' => 'wooRequestConfiguration',
				'woo_collection_query_schema' => 'wooCollectionQuery',
			][$key] ?? $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid): Folder {
				$folder = $this->createMock(Folder::class);
				$folder->method('search')->willReturn($this->files[$uid] ?? []);
				return $folder;
			}
		);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $root, logger: $logger);
		$plans = new WooSearchPlans(settingsService: $settings);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(fn (): bool => $this->mayRead);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(fn (): bool => $this->mayChange);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new WooCollectionController(
			appName: 'dossiq',
			request: $request,
			plans: $plans,
			collection: new WooCollection(
				settingsService: $settings,
				caseDocuments: $caseDocuments,
				store: new DocumentRecordStore(settingsService: $settings),
				assessments: new WOODocumentAssessmentService(settingsService: $settings, userSession: $session, logger: $logger, caseDocuments: $caseDocuments),
				plans: $plans,
			),
			queries: new WooCollectionQueries(settingsService: $settings, rootFolder: $root, sources: $this->createMock(WooSources::class), caseDocuments: $caseDocuments),
			accessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	public function testAPlanIsRecordedAndReadBack(): void {
		$this->params = ['custodians' => [['name' => 'Wethouder Ruimte']], 'systems' => ['files'], 'periodFrom' => '2025-01-01', 'periodTo' => '2025-12-31', 'terms' => 'Stationsweg'];

		self::assertSame(Http::STATUS_OK, $this->controller()->recordPlan(id: self::CASE_ID)->getStatus());
		$read = $this->controller()->plan(id: self::CASE_ID)->getData();
		self::assertTrue($read['recorded']);
		self::assertSame('pjansen', $read['plan']['recordedBy']);

		$this->params['terms'] = '';
		$refused = $this->controller()->recordPlan(id: self::CASE_ID);
		self::assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		self::assertSame('Enter what to search for.', $refused->getData()['message']);
	}//end testAPlanIsRecordedAndReadBack()

	public function testTheReportRefusesAUserWithoutCaseAccess(): void {
		$this->mayRead = false;
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->report(id: self::CASE_ID)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->rerun(id: self::CASE_ID, queryId: 'q')->getStatus());

		$this->mayRead = true;
		$this->mayChange = false;
		self::assertSame(Http::STATUS_OK, $this->controller()->report(id: self::CASE_ID)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->recordPlan(id: self::CASE_ID)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->exclude(id: self::CASE_ID, documentRef: 'doc-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->storeQuery(id: self::CASE_ID)->getStatus());
	}//end testTheReportRefusesAUserWithoutCaseAccess()

	public function testExcludingAnAssessedDocumentAnswers409(): void {
		$this->register->seed('informatieobject', 'doc-1', ['title' => 'Advies']);
		$this->register->seed('zaakinformatieobject', 'j-1', ['case' => self::CASE_ID, 'informatieobject' => 'doc-1']);
		$this->register->seed('wooDocumentAssessment', 'a-1', ['caseRef' => self::CASE_ID, 'documentRef' => 'doc-1']);
		$this->params = ['reason' => 'out-of-period'];

		$response = $this->controller()->exclude(id: self::CASE_ID, documentRef: 'doc-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('document_assessed', $response->getData()['error']);

		$this->register->rows['wooDocumentAssessment'] = [];
		self::assertSame(Http::STATUS_CREATED, $this->controller()->exclude(id: self::CASE_ID, documentRef: 'doc-1')->getStatus());
		self::assertSame(1, $this->controller()->report(id: self::CASE_ID)->getData()['excluded']);
	}//end testExcludingAnAssessedDocumentAnswers409()

	/**
	 * REQ-WRC-004: a colleague with read access re-runs a stored query.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function testAColleagueCanRerunAQuery(): void {
		$this->params = ['source' => 'files', 'terms' => 'Stationsweg', 'resultKeys' => ['1', '2']];
		$stored = $this->controller()->storeQuery(id: self::CASE_ID)->getData()['query'];

		$this->uid = 'abakker';
		$this->mayChange = false;
		$this->files['abakker'] = array_map(
			function (int $id): File {
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn($id);
				$file->method('getName')->willReturn('f' . $id);
				$file->method('getPath')->willReturn('/abakker/files/f' . $id);
				return $file;
			},
			[1, 2, 3]
		);

		$response = $this->controller()->rerun(id: self::CASE_ID, queryId: $stored['id']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['1' => false, '2' => false, '3' => true], array_column($response->getData()['rows'], 'new', 'key'));
		self::assertCount(1, $this->controller()->queries(id: self::CASE_ID)->getData()['queries']);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->rerun(id: self::CASE_ID, queryId: 'nope')->getStatus());
	}//end testAColleagueCanRerunAQuery()
}//end class
