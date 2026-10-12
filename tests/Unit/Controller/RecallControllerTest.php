<?php

/**
 * Unit tests for the recall controller.
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

use OCA\Dossiq\Controller\RecallController;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\RecallSamples;
use OCA\Dossiq\Review\ReviewBatches;
use OCA\Dossiq\Review\ReviewConfiguration;
use OCA\Dossiq\Review\ReviewDepth;
use OCA\Dossiq\Review\StoppingRuleMet;
use OCA\Dossiq\Review\StoppingRules;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\RecallEstimator;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The stopping rule and recall samples through the routes, on the real services.
 *
 * @covers \OCA\Dossiq\Controller\RecallController
 * @uses \OCA\Dossiq\Review\RecallSamples
 * @uses \OCA\Dossiq\Review\StoppingRules
 * @uses \OCA\Dossiq\Review\StoppingRuleMet
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Review\ReviewBatches
 * @uses \OCA\Dossiq\Review\ReviewConfiguration
 * @uses \OCA\Dossiq\Review\ReviewDepth
 * @uses \OCA\Dossiq\Woo\RecallEstimator
 * @uses \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 */
class RecallControllerTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Case X with four unmarked documents.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		foreach (range(1, 4) as $n) {
			$this->store->seed(schema: 'document', uuid: 'doc-'.$n, row: ['case' => 'case-x']);
			$this->store->seed(schema: 'documentReview', uuid: 'review-'.$n, row: ['case' => 'case-x', 'documentRef' => 'doc-'.$n, 'relevance' => 'unmarked']);
		}
	}//end setUp()

	/**
	 * The controller for a user with the given access.
	 *
	 * @param bool $read Whether the user may read the case.
	 * @param bool $mutate Whether the user may change the case.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return RecallController The controller.
	 */
	private function controller(bool $read = true, bool $mutate = true, array $params = []): RecallController {
		$config = [
			'register' => 'dossiq',
			'document_schema' => 'document',
			'document_review_schema' => 'documentReview',
			'review_batch_schema' => 'reviewBatch',
			'stopping_rule_schema' => 'stoppingRule',
			'recall_sample_schema' => 'recallSample',
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($read);
		$guard->method('hasCaseMutationAccess')->willReturn($mutate);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler-h');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$tasks = $this->createMock(EngineTaskGateway::class);
		$tasks->method('mirrorImport')->willReturn('engine-task-1');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(true);

		$reviews = new DocumentRelevance(settingsService: $settings, logger: $logger);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger);
		$rules = new StoppingRules(settingsService: $settings, reviews: $reviews, logger: $logger);
		$batches = new ReviewBatches(
			settingsService: $settings,
			reviews: $reviews,
			caseDocuments: $caseDocuments,
			configuration: new ReviewConfiguration(settingsService: $settings),
			tasks: $tasks,
			userManager: $userManager,
			l10n: $l10n,
			reviewerAccess: $this->createMock(\OCA\Dossiq\Service\Access\CaseReviewerAccess::class),
			logger: $logger,
		);

		return new RecallController(
			request: $request,
			rules: $rules,
			samples: new RecallSamples(
				settingsService: $settings,
				reviews: $reviews,
				caseDocuments: $caseDocuments,
				batches: $batches,
				depth: new ReviewDepth(),
				rules: $rules,
				estimator: new RecallEstimator(),
				met: new StoppingRuleMet(settingsService: $settings, timeline: $this->createMock(CaseTimeline::class), logger: $logger),
				logger: $logger,
			),
			caseAccessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * Before any marking the rule is declared (201) and the recall read shows it.
	 *
	 * @return void
	 */
	public function testDeclaringBeforeReviewAnswers201(): void {
		$response = $this->controller(params: ['targetRecall' => '0.8', 'confidence' => '0.95'])->declareStoppingRule(id: 'case-x');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('handler-h', $response->getData()['declaredBy']);
		$read = $this->controller()->recall(id: 'case-x')->getData();
		$this->assertSame(0.8, $read['rule']['targetRecall']);
		$this->assertFalse($read['reviewStarted']);
		$this->assertSame([], $read['samples']);
	}//end testDeclaringBeforeReviewAnswers201()

	/**
	 * Once a document is marked, declaring answers 409 with the sentence.
	 *
	 * @return void
	 */
	public function testDeclaringAfterTheFirstMarkingAnswers409(): void {
		$this->store->seed(schema: 'documentReview', uuid: 'review-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'in-scope']);

		$response = $this->controller(params: ['targetRecall' => '0.7', 'confidence' => '0.95'])->declareStoppingRule(id: 'case-x');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('review-stopping-rule-locked', $response->getData()['error']);
		$this->assertStringContainsString('declared before review', $response->getData()['message']);
	}//end testDeclaringAfterTheFirstMarkingAnswers409()

	/**
	 * Without mutation access nothing is drawn or declared; without read access nothing is read.
	 *
	 * @return void
	 */
	public function testDrawingRefusesWithoutMutationAccess(): void {
		$this->assertSame(403, $this->controller(mutate: false, params: ['size' => 2])->drawSample(id: 'case-x')->getStatus());
		$this->assertSame(403, $this->controller(mutate: false)->declareStoppingRule(id: 'case-x')->getStatus());
		$this->assertSame(403, $this->controller(read: false)->recall(id: 'case-x')->getStatus());
		$this->assertSame([], $this->store->all('recallSample'));
	}//end testDrawingRefusesWithoutMutationAccess()

	/**
	 * Draw two, judge both through the route: the estimate appears and the recall read lists it.
	 *
	 * @return void
	 */
	public function testDrawThenJudgeThroughTheRoutes(): void {
		$drawn = $this->controller(params: ['size' => 2])->drawSample(id: 'case-x');
		$this->assertSame(201, $drawn->getStatus());
		$sample = $drawn->getData();
		$this->assertSame(['judged' => 0, 'total' => 2], $sample['progress']);
		$this->assertNotEmpty($this->store->all('reviewBatch'));

		foreach ($sample['documents'] as $ref) {
			$judged = $this->controller(params: ['documentRef' => $ref, 'inScope' => 'false'])->judge(id: 'case-x', sampleId: $sample['id']);
		}

		$this->assertSame(200, $judged->getStatus());
		$this->assertSame(0, $judged->getData()['k']);
		$this->assertSame(1.0, $judged->getData()['recallEstimate']);
		$listed = $this->controller()->recall(id: 'case-x')->getData()['samples'];
		$this->assertSame(['judged' => 2, 'total' => 2], $listed[0]['progress']);
	}//end testDrawThenJudgeThroughTheRoutes()

	/**
	 * A judgement without in or out, and a size larger than the null set, answer 422.
	 *
	 * @return void
	 */
	public function testUnreadableInputAnswers422(): void {
		$this->assertSame(422, $this->controller(params: ['documentRef' => 'doc-1'])->judge(id: 'case-x', sampleId: 's')->getStatus());
		$response = $this->controller(params: ['size' => 9])->drawSample(id: 'case-x');
		$this->assertSame(422, $response->getStatus());
		$this->assertSame('review-recall-size', $response->getData()['error']);
	}//end testUnreadableInputAnswers422()
}//end class
