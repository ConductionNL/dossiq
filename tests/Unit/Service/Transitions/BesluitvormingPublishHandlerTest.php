<?php

/**
 * BesluitvormingPublishHandler Unit Tests
 *
 * The handler used to read `$result['ok']`, a key PublicationService::publish()
 * has never returned, so every completed publication was logged as a failure.
 * These tests assert the verdict against what the dispatcher actually does:
 * a returned record is a publication, a thrown exception is a failure.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use InvalidArgumentException;
use OCA\Dossiq\Service\PublicationService;
use OCA\Dossiq\Service\Transitions\BesluitvormingPublishHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\BesluitvormingPublishHandler
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class BesluitvormingPublishHandlerTest extends TestCase {
	/**
	 * The publication record publish() actually returns.
	 *
	 * @return array<string, mixed>
	 */
	private function publicationRecord(): array {
		return [
			'caseId' => 'case-3',
			'channel' => 'website',
			'publishedAt' => '2026-09-09T10:00:00+00:00',
			'publications' => [['channel' => 'website']],
			'delivery' => ['status' => 'accepted'],
		];
	}//end publicationRecord()

	/**
	 * A publication that happened is recorded as a success.
	 *
	 * This is the assertion that was missing: the old handler did the work
	 * and then reported `succeeded: false` on every single run.
	 *
	 * @return void
	 */
	public function testASuccessfulPublicationRecordsSuccess(): void {
		$publicationService = $this->createMock(PublicationService::class);
		$publicationService->expects(self::once())
			->method('publish')
			->with('case-3', ['channel' => 'website'])
			->willReturn($this->publicationRecord());

		$handler = new BesluitvormingPublishHandler(
			publicationService: $publicationService,
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'besluitvormingPublish'],
			case: ['id' => 'case-3'],
			transitionContext: ['transitionLabel' => 'Bekendmaking'],
		);

		self::assertTrue($result->succeeded);
		self::assertNull($result->error);
		self::assertSame('website', $result->data['channel']);
		self::assertSame(['status' => 'accepted'], $result->data['delivery']);
	}//end testASuccessfulPublicationRecordsSuccess()

	/**
	 * A refused delivery still leaves the publication recorded, and the
	 * delivery record travels in the action's data so the case can show it.
	 *
	 * @return void
	 */
	public function testARefusedDeliveryStillCarriesItsRecord(): void {
		$record = $this->publicationRecord();
		$record['delivery'] = ['status' => 'refused', 'reason' => 'no_route'];

		$publicationService = $this->createMock(PublicationService::class);
		$publicationService->method('publish')->willReturn($record);

		$handler = new BesluitvormingPublishHandler(
			publicationService: $publicationService,
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'besluitvormingPublish'],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('refused', $result->data['delivery']['status']);
	}//end testARefusedDeliveryStillCarriesItsRecord()

	/**
	 * OpenRegister being away is how publish() reports a real failure.
	 *
	 * @return void
	 */
	public function testAThrownFailureIsRecordedAsAFailure(): void {
		$publicationService = $this->createMock(PublicationService::class);
		$publicationService->method('publish')
			->willThrowException(new RuntimeException('OpenRegister is not available'));

		$handler = new BesluitvormingPublishHandler(
			publicationService: $publicationService,
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'besluitvormingPublish'],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('publication_failed', $result->error);
	}//end testAThrownFailureIsRecordedAsAFailure()

	/**
	 * An unsupported channel is a failure too, and does not leak the message.
	 *
	 * @return void
	 */
	public function testAnInvalidChannelIsRecordedAsAFailure(): void {
		$publicationService = $this->createMock(PublicationService::class);
		$publicationService->method('publish')
			->willThrowException(new InvalidArgumentException('Invalid publication channel: fax'));

		$handler = new BesluitvormingPublishHandler(
			publicationService: $publicationService,
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'besluitvormingPublish'],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('publication_failed', $result->error);
	}//end testAnInvalidChannelIsRecordedAsAFailure()

	/**
	 * Without a case id nothing is published.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseHasNoId(): void {
		$publicationService = $this->createMock(PublicationService::class);
		$publicationService->expects(self::never())->method('publish');

		$handler = new BesluitvormingPublishHandler(
			publicationService: $publicationService,
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'besluitvormingPublish'],
			case: [],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('no_case_id', $result->error);
	}//end testFailsWhenTheCaseHasNoId()
}//end class
