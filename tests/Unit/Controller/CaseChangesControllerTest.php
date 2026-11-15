<?php

/**
 * CaseChangesController tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Controller\CaseChangesController;
use OCA\Dossiq\Service\Board\CaseChangesFeed;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\CaseChangesController
 */
class CaseChangesControllerTest extends TestCase {

	/** @var CaseChangesFeed&MockObject */
	private CaseChangesFeed $feed;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	protected function setUp(): void {
		$this->feed = $this->createMock(CaseChangesFeed::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
	}

	private function controller(bool $signedIn = true): CaseChangesController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('reader');
		}

		$this->session->method('getUser')->willReturn($user);

		return new CaseChangesController('dossiq', $this->createMock(IRequest::class), $this->session, $this->logger, $this->feed);
	}

	public function testAnonymousCallerIsRefused(): void {
		$this->feed->expects($this->never())->method('since');

		$response = $this->controller(false)->index('2026-01-01T00:00:00Z');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}

	public function testWithoutSinceItReturnsNoChangesAndTheCurrentMoment(): void {
		$this->feed->expects($this->never())->method('since');
		$before = new DateTimeImmutable('-1 second');

		$data = $this->controller()->index('  ')->getData();

		$this->assertSame([], $data['changes']);
		$now = new DateTimeImmutable($data['now']);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
		$this->assertLessThanOrEqual(time() + 1, $now->getTimestamp());
	}

	public function testAnUnparseableSinceIsABadRequest(): void {
		$this->feed->expects($this->never())->method('since');

		$response = $this->controller()->index('not a moment');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'since must be an ISO 8601 moment'], $response->getData());
	}

	public function testARecentSinceIsPassedWithTheReaderAndScope(): void {
		$since = (new DateTimeImmutable('-5 minutes'))->format(DATE_ATOM);
		$this->feed->expects($this->once())->method('since')
			->with(
				'reader',
				$this->callback(fn (DateTimeImmutable $moment): bool => $moment->format(DATE_ATOM) === $since),
				['caseType' => 'ct-1', 'assignedGroup' => 'team-a'],
			)
			->willReturn(['changes' => [['id' => 'c1']], 'now' => 'n']);

		$response = $this->controller()->index(' ' . $since . ' ', 'ct-1', 'team-a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['changes' => [['id' => 'c1']], 'now' => 'n'], $response->getData());
	}

	public function testASinceOlderThanOneDayIsClampedToOneDayAgo(): void {
		$floor = new DateTimeImmutable('-1 day -5 seconds');
		$this->feed->expects($this->once())->method('since')
			->with(
				'reader',
				$this->callback(
					fn (DateTimeImmutable $moment): bool => $moment > $floor && $moment <= new DateTimeImmutable('-1 day')
				),
				['caseType' => '', 'assignedGroup' => ''],
			)
			->willReturn([]);

		$this->controller()->index('2000-01-01T00:00:00Z');
	}

	public function testAFeedFailureIsLoggedAndReportedAsInternalError(): void {
		$this->feed->method('since')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with('Case changes could not be read', ['error' => 'db down']);

		$response = $this->controller()->index((new DateTimeImmutable('-1 minute'))->format(DATE_ATOM));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Internal error'], $response->getData());
	}
}
