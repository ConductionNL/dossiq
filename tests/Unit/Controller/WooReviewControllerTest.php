<?php

/**
 * Unit tests for the Woo review controller.
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

use OCA\Dossiq\Controller\WooReviewController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooDocumentReviews;
use OCA\Dossiq\Woo\WooReviewSummary;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Relevance and the summary through the real reviews, summary and assessment service.
 *
 * @covers \OCA\Dossiq\Controller\WooReviewController
 * @covers \OCA\Dossiq\Woo\WooReviewSummary
 * @covers \OCA\Dossiq\Woo\WooDocumentReviews
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 */
class WooReviewControllerTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Seed the ten-document fixture: 6 in scope, 3 out, 1 unmarked, 4 in-scope ones assessed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$marks = ['in-scope', 'in-scope', 'in-scope', 'in-scope', 'in-scope', 'in-scope', 'out-of-scope', 'out-of-scope', 'out-of-scope', 'unmarked'];
		$verdicts = ['openbaar', 'openbaar', 'deels_openbaar', 'niet_openbaar'];
		foreach ($marks as $n => $relevance) {
			$this->store->seed(schema: 'document', uuid: 'doc-'.$n, row: ['case' => 'case-x']);
			$this->store->seed(schema: 'wooDocumentReview', uuid: 'review-'.$n, row: ['case' => 'case-x', 'documentRef' => 'doc-'.$n, 'relevance' => $relevance]);
			if ($n < 4) {
				$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-'.$n, row: ['caseRef' => 'case-x', 'documentRef' => 'doc-'.$n, 'classification' => $verdicts[$n]]);
			}
		}
	}//end setUp()

	/**
	 * The controller for a user with the given access.
	 *
	 * @param bool $read Whether the user may read the case.
	 * @param bool $mutate Whether the user may change the case.
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return WooReviewController The controller.
	 */
	private function controller(bool $read = true, bool $mutate = true, array $params = []): WooReviewController {
		$config = ['register' => 'dossiq', 'document_schema' => 'document', 'woo_assessment_schema' => 'wooDocumentAssessment', 'woo_review_schema' => 'wooDocumentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$reviews = new WooDocumentReviews(settingsService: $settings, logger: $logger);
		$assessments = new WOODocumentAssessmentService($settings, $this->createMock(IUserSession::class), $logger, null, null, $reviews);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($read);
		$guard->method('hasCaseMutationAccess')->willReturn($mutate);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reviewer-a');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new WooReviewController(
			request: $request,
			reviews: $reviews,
			summary: new WooReviewSummary(
				settingsService: $settings,
				reviews: $reviews,
				assessments: $assessments,
				caseDocuments: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger),
			),
			caseAccessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * The scenario: inScope 6, outOfScope 3, unmarked 1, verdicts for 4, outstanding the 2 and the 1.
	 *
	 * @return void
	 */
	public function testTheSummaryReportsRelevanceBesideVerdicts(): void {
		$response = $this->controller()->summary(id: 'case-x');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(10, $data['documents']);
		$this->assertSame(['unmarked' => 1, 'inScope' => 6, 'outOfScope' => 3], $data['relevance']);
		$this->assertSame(['openbaar' => 2, 'deels_openbaar' => 1, 'niet_openbaar' => 1, 'total' => 4], $data['verdicts']);
		$this->assertEqualsCanonicalizing(['doc-4', 'doc-5', 'doc-9'], $data['outstanding']['documents']);
	}//end testTheSummaryReportsRelevanceBesideVerdicts()

	/**
	 * A reviewer marks a document, and a rule's marking it changes is recorded as overturned.
	 *
	 * @return void
	 */
	public function testMarkingRecordsTheReviewerAndAnOverturnedRule(): void {
		$this->store->rows['wooDocumentReview']['review-7'] += ['relevanceSource' => 'rule', 'rule' => 'Nieuwsbrieven'];

		$response = $this->controller(params: ['relevance' => 'in-scope'])->relevance(id: 'case-x', documentRef: 'doc-7');

		$this->assertSame(200, $response->getStatus());
		$saved = $this->store->rows['wooDocumentReview']['review-7'];
		$this->assertSame('in-scope', $saved['relevance']);
		$this->assertSame('reviewer', $saved['relevanceSource']);
		$this->assertSame('Nieuwsbrieven', $saved['overturnedRule']);
		$this->assertSame('reviewer-a', $saved['markedBy']);
		$this->assertCount(10, $this->store->all('wooDocumentReview'));
	}//end testMarkingRecordsTheReviewerAndAnOverturnedRule()

	/**
	 * A document without a review gets one when it is first marked; an unknown relevance is refused.
	 *
	 * @return void
	 */
	public function testAFirstMarkCreatesTheReviewAndNonsenseIsRefused(): void {
		$this->store->seed(schema: 'document', uuid: 'doc-new', row: ['case' => 'case-x']);

		$this->assertSame(200, $this->controller(params: ['relevance' => 'out-of-scope'])->relevance(id: 'case-x', documentRef: 'doc-new')->getStatus());
		$created = array_values(array_filter($this->store->all('wooDocumentReview'), static fn (array $r): bool => $r['documentRef'] === 'doc-new'));
		$this->assertSame('out-of-scope', $created[0]['relevance']);
		$this->assertSame('case-x', $created[0]['case']);

		$refused = $this->controller(params: ['relevance' => 'maybe'])->relevance(id: 'case-x', documentRef: 'doc-new');
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('woo-relevance-unknown', $refused->getData()['error']);
	}//end testAFirstMarkCreatesTheReviewAndNonsenseIsRefused()

	/**
	 * A user without a grant on the case is refused on both routes, and nothing is written.
	 *
	 * @return void
	 */
	public function testAUserWithoutAGrantOnTheCaseIsRefused(): void {
		$writes = $this->store->writes;

		$this->assertSame(403, $this->controller(read: false, mutate: false)->summary(id: 'case-x')->getStatus());
		$this->assertSame(403, $this->controller(read: true, mutate: false, params: ['relevance' => 'in-scope'])->relevance(id: 'case-x', documentRef: 'doc-1')->getStatus());
		$this->assertSame($writes, $this->store->writes);
	}//end testAUserWithoutAGrantOnTheCaseIsRefused()
}//end class
