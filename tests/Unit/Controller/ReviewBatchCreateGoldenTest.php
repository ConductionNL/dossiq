<?php

/**
 * Golden record of creating a review batch.
 *
 * Records, per world, the status and body `createBatch` answers with and every
 * call it makes, so a refactor cannot change who may make a review batch, which
 * documents it takes, or how a refusal reads.
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

use OCA\Dossiq\Controller\DocumentReviewController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\BatchSelection;
use OCA\Dossiq\Review\ReviewBatches;
use OCA\Dossiq\Review\ReviewSummary;
use OCA\Dossiq\Review\ReviewWrites;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for DocumentReviewController::createBatch.
 *
 * @covers \OCA\Dossiq\Controller\DocumentReviewController
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class ReviewBatchCreateGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testCreateBatchAcrossWorlds(): void {
		$params = ['name' => 'Batch A', 'assignee' => 'reviewer', 'documents' => ['doc-1', 2, ['nested'], null], 'filter' => ['custodian' => 'alice', 'odd' => ['x']]];
		$allowed = ['user' => true, 'mutate' => true, 'select' => 'ok', 'taken' => [], 'create' => 'ok'];
		$worlds = [
			'not signed in' => [$params, ['user' => false] + $allowed],
			'no write access on the case' => [$params, ['mutate' => false] + $allowed],
			'made' => [$params, $allowed],
			'made without parameters' => [[], $allowed],
			'a document already in a batch' => [$params, ['taken' => ['doc-1' => 'Batch B']] + $allowed],
			'selection refused' => [$params, ['select' => 'refuse'] + $allowed],
			'create refused' => [$params, ['create' => 'refuse'] + $allowed],
		];

		$record = [];
		foreach ($worlds as $name => [$request, $world]) {
			$this->calls = [];
			$response = $this->controllerFor(params: $request, world: $world)->createBatch('case-1');
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'review-batch-create', value: $record);
	}//end testCreateBatchAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return DocumentReviewController
	 */
	private function controllerFor(array $params, array $world): DocumentReviewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $user : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId) use ($world): bool {
				$this->calls[] = 'hasCaseMutationAccess ' . $caseId;
				return $world['mutate'];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new DocumentReviewController(
			request: $request,
			writes: $this->createMock(ReviewWrites::class),
			summary: $this->createMock(ReviewSummary::class),
			batches: $this->batchesFor(world: $world),
			selection: $this->selectionFor(world: $world),
			caseAccessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controllerFor()

	/**
	 * A selection that records what it is asked for.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return BatchSelection
	 */
	private function selectionFor(array $world): BatchSelection {
		$selection = $this->createMock(BatchSelection::class);
		$selection->method('select')->willReturnCallback(
			function (string $caseId, array $documents, array $filter) use ($world): array {
				$this->calls[] = 'select ' . json_encode([$caseId, $documents, $filter]);
				if ($world['select'] === 'refuse') {
					throw new RefusedException('review-batch-filter-unknown', 'unknown filter', 422);
				}

				return ['doc-1', 'doc-2'];
			}
		);

		return $selection;
	}//end selectionFor()

	/**
	 * Batches that record each call and answer per the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return ReviewBatches
	 */
	private function batchesFor(array $world): ReviewBatches {
		$batches = $this->createMock(ReviewBatches::class);
		$batches->method('taken')->willReturnCallback(
			function (string $caseId, array $documents) use ($world): array {
				$this->calls[] = 'taken ' . json_encode([$caseId, $documents]);
				return $world['taken'];
			}
		);
		$batches->method('create')->willReturnCallback(
			function (string $caseId, string $name, string $assignee, array $documents, string $userId, array $filter = []) use ($world): array {
				$this->calls[] = 'create ' . json_encode([$caseId, $name, $assignee, $documents, $userId, $filter]);
				if ($world['create'] === 'refuse') {
					throw new RefusedException('review-batch-name-required', 'name required');
				}

				return ['id' => 'batch-1', 'name' => $name];
			}
		);

		return $batches;
	}//end batchesFor()
}//end class
