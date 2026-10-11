<?php

/**
 * TermHandlerNotice unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\CasePriorityRaiseService;
use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermHandlerNotice;
use OCA\Dossiq\Service\TermijnService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The handler is told on the rungs the term definition names, and on no other.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermHandlerNotice
 * @uses \OCA\Dossiq\Service\DeadlineEscalationService
 */
class TermHandlerNoticeTest extends TestCase {

	/**
	 * Objects by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * What each notification was given, by object id.
	 *
	 * @var array<int, \ArrayObject<string, mixed>>
	 */
	private array $records = [];

	/**
	 * Notifications that went out, as what they were given.
	 *
	 * @var array<int, \ArrayObject<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Build the notice over the in-memory objects.
	 *
	 * @return TermHandlerNotice The notice.
	 */
	private function notice(): TermHandlerNotice {
		$objects = new class ($this->objects) {
			/**
			 * @param array<string, array<string, array<string, mixed>>> $rows By schema, then id.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register, string $schema): ?array {
				return $this->rows[$schema][$id] ?? null;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_definitie_schema' => 'deadlineDefinition',
				default => '',
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$record = new \ArrayObject(['user' => null, 'subject' => null, 'params' => []]);
			foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
				$notification->method($setter)->willReturnSelf();
			}

			$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification, $record) {
				$record['user'] = $user;
				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($notification, $record) {
				$record['subject'] = $subject;
				$record['params'] = $params;
				return $notification;
			});
			$this->records[spl_object_id($notification)] = $record;

			return $notification;
		});
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$this->sent[] = $this->records[spl_object_id($notification)];
		});

		return new TermHandlerNotice($settings, $manager, $this->createMock(LoggerInterface::class));
	}//end notice()

	/**
	 * A Woo-shaped term with `tellHandlerAt: [7, 0]` on a case held by `anna`.
	 *
	 * @param mixed $tellHandlerAt What the definition declares.
	 * @param mixed $assignee      Who holds the case.
	 *
	 * @return array<string, mixed> The instance.
	 */
	private function term(mixed $tellHandlerAt = [7, 0], mixed $assignee = 'anna'): array {
		$definition = ['id' => 'td-1', 'caseType' => 'woo-verzoek'];
		if ($tellHandlerAt !== null) {
			$definition['tellHandlerAt'] = $tellHandlerAt;
		}

		$this->objects['deadlineDefinition']['td-1'] = $definition;
		$this->objects['case']['case-1'] = ['id' => 'case-1', 'assignee' => $assignee];

		return ['id' => 'ti-1', 'case' => 'case-1', 'deadlineDefinition' => 'td-1', 'endDateCurrent' => '2026-11-07'];
	}//end term()

	/**
	 * Seven days before the end tells the handler the days left.
	 *
	 * @return void
	 */
	public function testANamedRungTellsTheHandler(): void {
		$instance = $this->term();

		self::assertTrue($this->notice()->tell(instance: $instance, threshold: 7));
		self::assertCount(1, $this->sent);
		self::assertSame('anna', $this->sent[0]['user']);
		self::assertSame(Notifier::SUBJECT_TERM_NEARING, $this->sent[0]['subject']);
		self::assertSame(7, $this->sent[0]['params']['daysRemaining']);
		self::assertSame('case-1', $this->sent[0]['params']['caseId']);
	}

	/**
	 * The end rung says the term ran out.
	 *
	 * @return void
	 */
	public function testTheEndRungSaysTheTermRanOut(): void {
		$instance = $this->term();

		self::assertTrue($this->notice()->tell(instance: $instance, threshold: 0));
		self::assertSame(Notifier::SUBJECT_TERM_EXCEEDED, $this->sent[0]['subject']);
	}

	/**
	 * A rung the definition does not name tells nobody.
	 *
	 * @return void
	 */
	public function testAnUnnamedRungTellsNobody(): void {
		$instance = $this->term();

		self::assertFalse($this->notice()->tell(instance: $instance, threshold: 14));
		self::assertSame([], $this->sent);
	}

	/**
	 * A definition that never asked tells nobody, so other case types keep
	 * their behaviour.
	 *
	 * @return void
	 */
	public function testADefinitionWithoutTheSettingTellsNobody(): void {
		$instance = $this->term(tellHandlerAt: null);

		self::assertFalse($this->notice()->tell(instance: $instance, threshold: 7));
		self::assertSame([], $this->sent);
	}

	/**
	 * A case nobody holds has nobody to tell.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnAssigneeTellsNobody(): void {
		$instance = $this->term(assignee: null);

		self::assertFalse($this->notice()->tell(instance: $instance, threshold: 7));
		self::assertSame([], $this->sent);
	}

	/**
	 * A notice that cannot go out answers false and logs an error; it never
	 * throws into the escalation that recorded the rung.
	 *
	 * @return void
	 */
	public function testAFailedSendAnswersFalseAndLogs(): void {
		$instance = $this->term();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willThrowException(new \RuntimeException('OpenRegister went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$notice = new TermHandlerNotice($settings, $this->createMock(IManager::class), $logger);

		self::assertFalse($notice->tell(instance: $instance, threshold: 7));
	}

	/**
	 * Wired from the caller: the escalation service tells the handler once,
	 * and a rung it already recorded tells nobody again.
	 *
	 * @return void
	 */
	public function testTheEscalationServiceTellsTheHandlerOncePerRung(): void {
		$instance = $this->term();
		$raiser = $this->createMock(CasePriorityRaiseService::class);
		$raiser->method('raiseForThreshold')->willReturn('normal');

		$escalation = new DeadlineEscalationService(
			termService: $this->createMock(TermijnService::class),
			priorityRaiseService: $raiser,
			logger: $this->createMock(LoggerInterface::class),
			handlerNotice: $this->notice(),
		);

		self::assertTrue($escalation->notifyThreshold($instance, 7));
		self::assertFalse($escalation->notifyThreshold($instance + ['notificatiesVerstuurd' => [7]], 7));
		self::assertCount(1, $this->sent);
	}
}//end class
