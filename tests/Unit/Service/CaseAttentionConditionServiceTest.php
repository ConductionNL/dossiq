<?php

/**
 * The attention conditions a case can raise, the reason each gives, and the context they read.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseAttentionConditionService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reasons per condition at a fixed moment, and the advice-request context read through OpenRegister.
 *
 * @covers \OCA\Dossiq\Service\CaseAttentionConditionService
 */
class CaseAttentionConditionServiceTest extends TestCase {

	/**
	 * The moment every reason is judged at.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-14T12:00:00+00:00');
	}//end now()

	/**
	 * An object service double answering searchObjectsBySlug() and recording the call.
	 *
	 * @param array<int, mixed>|RuntimeException $answer The rows, or the exception to throw.
	 *
	 * @return object
	 */
	private static function objectService(array|RuntimeException $answer): object {
		return new class($answer) {
			/** @var list<array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param array<int, mixed>|RuntimeException $answer
			 */
			public function __construct(private array|RuntimeException $answer) {
			}

			/**
			 * @param array<string, mixed> $filters
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = [$register, $schema, $filters];
				if ($this->answer instanceof RuntimeException) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end objectService()

	/**
	 * The service over a settings double with the given object service and register.
	 *
	 * @param object|null $objectService The object service, or null.
	 * @param string $register The configured register.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CaseAttentionConditionService
	 */
	private function service(?object $objectService, string $register = 'dossiq', ?LoggerInterface $logger = null): CaseAttentionConditionService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $key === 'register' ? $register : '');

		return new CaseAttentionConditionService($logger ?? $this->createMock(LoggerInterface::class), $settings);
	}//end service()

	/**
	 * Only the advice condition needs context, and only when it is missing is it unanswerable.
	 *
	 * @return void
	 */
	public function testOnlyTheAdviceConditionNeedsContext(): void {
		$service = new CaseAttentionConditionService($this->createMock(LoggerInterface::class));

		$this->assertTrue($service->unanswerable('advice-request-overdue', []));
		$this->assertFalse($service->unanswerable('advice-request-overdue', ['adviceRequests' => []]));
		$this->assertFalse($service->unanswerable('term-exceeded', []));
		$this->assertFalse($service->unanswerable('unknown', []));
	}//end testOnlyTheAdviceConditionNeedsContext()

	/**
	 * Open advice requests past their date are counted; answered, closed or undated ones and malformed rows are not.
	 *
	 * @return void
	 */
	public function testOverdueAdviceRequestsAreCounted(): void {
		$service = new CaseAttentionConditionService($this->createMock(LoggerInterface::class));
		$context = [
			'adviceRequests' => [
				['status' => 'open', 'deadline' => '2026-11-01'],
				['status' => 'Requested', 'deadline' => '2026-11-13'],
				['status' => 'open', 'deadline' => '2026-11-14'],
				['status' => ' Received ', 'deadline' => '2026-10-01'],
				['status' => 'withdrawn', 'deadline' => '2026-10-01'],
				['status' => 'open', 'deadline' => ''],
				['status' => 'open', 'deadline' => 'not a date'],
				'not-a-row',
			],
		];

		$this->assertSame('2 advice request(s) are past the date they were asked for.', $service->reasonFor('advice-request-overdue', [], self::now(), $context));
		$this->assertSame('', $service->reasonFor('advice-request-overdue', [], self::now(), ['adviceRequests' => 'broken']));
		$this->assertSame('', $service->reasonFor('advice-request-overdue', [], self::now()));
	}//end testOverdueAdviceRequestsAreCounted()

	/**
	 * A case waiting on the applicant says so; anything but a true flag does not.
	 *
	 * @return void
	 */
	public function testAnOpenAanvullingsverzoek(): void {
		$service = new CaseAttentionConditionService($this->createMock(LoggerInterface::class));

		$this->assertSame('This case is waiting on the applicant to complete their submission.', $service->reasonFor('aanvullingsverzoek-open', ['waitingOnApplicant' => true], self::now()));
		$this->assertSame('', $service->reasonFor('aanvullingsverzoek-open', ['waitingOnApplicant' => 'yes'], self::now()));
		$this->assertSame('', $service->reasonFor('aanvullingsverzoek-open', [], self::now()));
	}//end testAnOpenAanvullingsverzoek()

	/**
	 * A passed deadline (or deadlineDate) on an open case is exceeded; today, a final status or no date is not.
	 *
	 * @return void
	 */
	public function testAnExceededTerm(): void {
		$service = new CaseAttentionConditionService($this->createMock(LoggerInterface::class));
		$reason = 'The date this case had to be decided by has passed.';

		$this->assertSame($reason, $service->reasonFor('term-exceeded', ['deadline' => '2026-11-13'], self::now()));
		$this->assertSame($reason, $service->reasonFor('term-exceeded', ['deadlineDate' => '2026-01-01'], self::now()));
		$this->assertSame('', $service->reasonFor('term-exceeded', ['deadline' => '2026-11-14'], self::now()));
		$this->assertSame('', $service->reasonFor('term-exceeded', ['deadline' => '2026-11-13', 'isFinalStatus' => true], self::now()));
		$this->assertSame('', $service->reasonFor('term-exceeded', [], self::now()));
		$this->assertSame('', $service->reasonFor('something-else', ['deadline' => '2020-01-01'], self::now()));
	}//end testAnExceededTerm()

	/**
	 * The context is the case's advice requests, searched by slug with the case filter and a limit.
	 *
	 * @return void
	 */
	public function testTheContextIsTheCasesAdviceRequests(): void {
		$objects = self::objectService([['status' => 'open'], 'skipped']);

		$this->assertSame(['adviceRequests' => [['status' => 'open']]], $this->service($objects)->contextFor(' case-1 '));
		$this->assertSame([['dossiq', 'adviceRequest', ['case' => 'case-1', '_limit' => 100]]], $objects->calls);
	}//end testTheContextIsTheCasesAdviceRequests()

	/**
	 * Without a case id, settings, object service or register the context is empty and nothing is searched.
	 *
	 * @return void
	 */
	public function testNoContextWithoutAStore(): void {
		$objects = self::objectService([]);

		$this->assertSame([], $this->service($objects)->contextFor('  '));
		$this->assertSame([], $this->service($objects, '')->contextFor('case-1'));
		$this->assertSame([], $this->service(null)->contextFor('case-1'));
		$this->assertSame([], (new CaseAttentionConditionService($this->createMock(LoggerInterface::class)))->contextFor('case-1'));
		$this->assertSame([], $objects->calls);
	}//end testNoContextWithoutAStore()

	/**
	 * A failed read leaves the context empty and logs a warning naming the case.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndGivesNoContext(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not be read'),
			$this->callback(static fn (array $context): bool => $context['caseId'] === 'case-1' && $context['error'] === 'register down')
		);

		$this->assertSame([], $this->service(self::objectService(new RuntimeException('register down')), 'dossiq', $logger)->contextFor('case-1'));
	}//end testAFailedReadIsLoggedAndGivesNoContext()
}//end class
