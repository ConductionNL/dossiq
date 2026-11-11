<?php

/**
 * Golden test for TermHandlerNotice::tell: every setter the notification gets,
 * in order, and every reason it is not sent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTime;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermHandlerNotice;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TermHandlerNoticeGoldenTest extends TestCase {

	private array $calls = [];

	private function notice(array $rows): TermHandlerNotice {
		$objects  = new class ($rows) {
			public function __construct(private array $rows) {
			}

			public function find(string $id, string $register, string $schema): ?array {
				return ($this->rows[$schema][$id] ?? null);
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
		$calls   = &$this->calls;
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () use (&$calls): INotification {
				$calls[]      = ['create'];
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
					$notification->method($setter)->willReturnCallback(
						static function (...$args) use (&$calls, $setter, $notification): INotification {
							if ($args[0] instanceof DateTime) {
								$args[0] = 'now';
							}

							$calls[] = array_merge([$setter], $args);
							return $notification;
						}
					);
				}

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			static function (INotification $notification) use (&$calls): void {
				$calls[] = ['notify'];
			}
		);

		return new TermHandlerNotice($settings, $manager, new NullLogger());
	}//end notice()

	public function testEverySetterInOrder(): void {
		$rows = [
			'deadlineDefinition' => ['td-1' => ['tellHandlerAt' => [7, '0']]],
			'case' => ['case-1' => ['assignee' => 'anna']],
		];
		$instance = ['case' => 'case-1', 'deadlineDefinition' => 'td-1', 'endDateCurrent' => '2026-11-07'];

		$this->assertTrue($this->notice($rows)->tell(instance: $instance, threshold: 0));
		$this->assertSame(
			[
				['create'],
				['setApp', 'dossiq'],
				['setUser', 'anna'],
				['setDateTime', 'now'],
				['setObject', 'case', 'case-1'],
				['setSubject', Notifier::SUBJECT_TERM_EXCEEDED, ['caseId' => 'case-1', 'daysRemaining' => 0, 'deadline' => '2026-11-07']],
				['notify'],
			],
			$this->calls
		);

		$this->calls = [];
		$this->assertTrue($this->notice($rows)->tell(instance: ['case' => 'case-1', 'deadlineDefinition' => 'td-1'], threshold: 7));
		$this->assertSame(['setSubject', Notifier::SUBJECT_TERM_NEARING, ['caseId' => 'case-1', 'daysRemaining' => 7, 'deadline' => '']], $this->calls[5]);
	}//end testEverySetterInOrder()

	public function testNobodyToldAndNothingCreated(): void {
		$cases = ['case-1' => ['assignee' => 'anna'], 'case-2' => ['assignee' => ['anna']], 'case-3' => ['assignee' => '']];
		$rows  = ['deadlineDefinition' => ['td-1' => ['tellHandlerAt' => [7]]], 'case' => $cases];

		$this->assertFalse($this->notice($rows)->tell(instance: ['case' => 'case-1', 'deadlineDefinition' => 'td-1'], threshold: 3));
		$this->assertFalse($this->notice($rows)->tell(instance: ['case' => 'case-1', 'deadlineDefinition' => 'td-9'], threshold: 7));
		$this->assertFalse($this->notice($rows)->tell(instance: ['case' => 'case-1'], threshold: 7));
		foreach (['case-2', 'case-3', 'case-9', ''] as $caseId) {
			$this->assertFalse($this->notice($rows)->tell(instance: ['case' => $caseId, 'deadlineDefinition' => 'td-1'], threshold: 7));
		}

		$this->assertSame([], $this->calls);
	}//end testNobodyToldAndNothingCreated()
}//end class
