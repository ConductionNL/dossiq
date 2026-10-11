<?php

/**
 * Unit tests for CaseAssignmentService::reassign().
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-206-human-approval-of-ai-writes-is-hermiqs-policy
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseAssignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One case handed from one handler to another.
 */
class CaseAssignmentServiceTest extends TestCase {

	/**
	 * The fake OpenRegister object service: one stored case, and the patches it took.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Notifications sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objects = new class {
			/** @var array<string, array<string, mixed>> */
			public array $cases = ['case-1' => ['id' => 'case-1', 'title' => 'Omgevingsvergunning', 'assignee' => 'henk']];

			/** @var array<int, array<string, mixed>> */
			public array $patches = [];

			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return ($this->cases[$id] ?? null);
			}

			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				$this->patches[] = $data;
				$this->cases[$objectId] = array_merge($this->cases[$objectId], $data);

				return $this->cases[$objectId];
			}
		};

	}//end setUp()

	/**
	 * The service under test, wired to the fake.
	 *
	 * @return CaseAssignmentService
	 */
	private function service(): CaseAssignmentService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '');

		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$params = [];
			foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
				$notification->method($setter)->willReturnSelf();
			}

			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$params) {
				$params['user'] = $uid;
				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$params) {
				$params['subject'] = $subject;
				$params['parameters'] = $parameters;
				$this->sent[] = $params;
				return $notification;
			});

			return $notification;
		});

		return new CaseAssignmentService(
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			notificationManager: $notifications,
		);

	}//end service()

	public function testReassignWritesOnlyTheAssigneeAndTellsTheReceiver(): void {
		$result = $this->service()->reassign(caseId: 'case-1', toUser: 'fatima', actorId: 'coordinator');

		$this->assertSame([['assignee' => 'fatima']], $this->objects->patches);
		$this->assertSame('fatima', $result['assignee']);
		$this->assertSame('henk', $result['previousAssignee']);
		$this->assertCount(1, $this->sent);
		$this->assertSame('fatima', $this->sent[0]['user']);
		$this->assertSame('cases_reassigned', $this->sent[0]['subject']);
		$this->assertSame(['fromUser' => 'henk', 'count' => 1], $this->sent[0]['parameters']);

	}//end testReassignWritesOnlyTheAssigneeAndTellsTheReceiver()

	public function testThePatchFitsTheRealCaseSchema(): void {
		$this->service()->reassign(caseId: 'case-1', toUser: 'fatima', actorId: 'coordinator');

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/dossiq_register.json'), true);
		$properties = $register['components']['schemas']['case']['properties'];
		foreach ($this->objects->patches[0] as $key => $value) {
			$this->assertArrayHasKey($key, $properties, 'case schema has no property ' . $key);
			$this->assertSame('string', $properties[$key]['type']);
			$this->assertIsString($value);
		}

	}//end testThePatchFitsTheRealCaseSchema()

	public function testReassignToTheCurrentHandlerIsRefusedAndWritesNothing(): void {
		$this->expectExceptionObject(new RuntimeException('already_assigned'));
		try {
			$this->service()->reassign(caseId: 'case-1', toUser: 'henk', actorId: 'coordinator');
		} finally {
			$this->assertSame([], $this->objects->patches);
			$this->assertSame([], $this->sent);
		}

	}//end testReassignToTheCurrentHandlerIsRefusedAndWritesNothing()

	public function testReassignWithoutAReceiverIsRefused(): void {
		$this->expectExceptionObject(new RuntimeException('receiver_required'));
		$this->service()->reassign(caseId: 'case-1', toUser: '  ', actorId: 'coordinator');

	}//end testReassignWithoutAReceiverIsRefused()

	public function testAnUnknownCaseIsNotFound(): void {
		$this->expectExceptionObject(new RuntimeException('case_not_found'));
		$this->service()->reassign(caseId: 'case-404', toUser: 'fatima', actorId: 'coordinator');

	}//end testAnUnknownCaseIsNotFound()
}//end class
