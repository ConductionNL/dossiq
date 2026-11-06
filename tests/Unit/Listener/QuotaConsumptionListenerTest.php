<?php

/**
 * Creating a case or a tenant user consumes the tenant's quota.
 *
 * Driven with OpenRegister's own create events through the real gate and the
 * real quota decision; the quota row lives in memory.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\QuotaConsumptionListener;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\QuotaGate;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-TQ-ENF-01.
 *
 * @covers \OCA\Dossiq\Listener\QuotaConsumptionListener
 * @covers \OCA\Dossiq\Service\QuotaGate
 * @uses   \OCA\Dossiq\Service\TenantQuotaService
 * @uses   \OCA\Dossiq\Service\QuotaLimitLabel
 * @uses   \OCA\Dossiq\Service\ObjectSchemaSlugResolver
 * @uses   \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class QuotaConsumptionListenerTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	/** @var array<string, mixed> */
	private array $quota = [];

	private bool $bound = true;

	/** @var list<array{user: string, subject: string, params: array<string, mixed>}> */
	private array $sent = [];

	private QuotaConsumptionListener $listener;

	protected function setUp(): void {
		$this->quota = [
			'tenantRef' => self::TENANT,
			'quotaType' => 'cases_per_month',
			'limit' => 500,
			'currentUsage' => 0,
			'softLimitWarningPercent' => 80,
			'enforcement' => 'block',
		];

		$quotas = $this->getMockBuilder(TenantQuotaService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQuota', 'consume'])
			->getMock();
		$quotas->method('getQuota')->willReturnCallback(
			fn (string $tenantId, string $quotaType): ?array => ($quotaType === $this->quota['quotaType']) ? $this->quota : null
		);
		$quotas->method('consume')->willReturnCallback(
			function (string $tenantId, string $quotaType, int $amount = 1): array {
				$this->quota['currentUsage'] += $amount;
				return ['decision' => 'allow', 'soft' => false, 'reason' => 'within_limit', 'currentUsage' => $this->quota['currentUsage']];
			}
		);

		$tenant = $this->createMock(TenantContext::class);
		$tenant->method('isBound')->willReturnCallback(fn (): bool => $this->bound);
		$tenant->method('getTenantId')->willReturn(self::TENANT);
		$tenant->method('getTenant')->willReturn(['uuid' => self::TENANT, 'tier' => 'basic']);

		$register = new class {
			/**
			 * @param array<string, mixed> $config
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config): array {
				return [
					['tenantRef' => '11111111-1111-4111-8111-111111111111', 'userRef' => 'beheer', 'role' => 'tenant_admin'],
					['tenantRef' => '11111111-1111-4111-8111-111111111111', 'userRef' => 'handler', 'role' => 'member'],
				];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$record = ['user' => '', 'subject' => '', 'params' => []];
			$notification->method('setApp')->willReturnSelf();
			$notification->method('setDateTime')->willReturnSelf();
			$notification->method('setObject')->willReturnSelf();
			$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification, &$record): INotification {
				$record['user'] = $user;
				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($notification, &$record): INotification {
				$record['subject'] = $subject;
				$record['params'] = $params;
				$this->sent[] = $record;
				return $notification;
			});
			return $notification;
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$gate = new QuotaGate(
			quotas: $quotas,
			tenant: $tenant,
			settings: $settings,
			notifications: $notifications,
			l10n: $l10n,
			logger: new NullLogger(),
		);

		$this->listener = new QuotaConsumptionListener(
			gate: $gate,
			slugResolver: new ObjectSchemaSlugResolver($this->createMock(ContainerInterface::class), new NullLogger()),
		);
	}//end setUp()

	/**
	 * Under the limit the create goes through and is counted.
	 *
	 * @return void
	 */
	public function testUnderTheLimitTheCreateIsAllowedAndCounted(): void {
		$this->quota['currentUsage'] = 10;

		$creating = new ObjectCreatingEvent(object: $this->entity());
		$this->listener->handle($creating);
		$this->listener->handle(new ObjectCreatedEvent($this->entity()));

		$this->assertFalse($creating->isPropagationStopped());
		$this->assertSame(11, $this->quota['currentUsage']);
	}//end testUnderTheLimitTheCreateIsAllowedAndCounted()

	/**
	 * The create that reaches the limit exactly is still allowed.
	 *
	 * @return void
	 */
	public function testTheCreateThatReachesTheLimitIsAllowed(): void {
		$this->quota['currentUsage'] = 499;

		$creating = new ObjectCreatingEvent(object: $this->entity());
		$this->listener->handle($creating);

		$this->assertFalse($creating->isPropagationStopped());
	}//end testTheCreateThatReachesTheLimitIsAllowed()

	/**
	 * The 501st case is refused naming the limit and the tier.
	 *
	 * @return void
	 */
	public function testPastTheHardLimitTheCreateIsRefused(): void {
		$this->quota['currentUsage'] = 500;

		$creating = new ObjectCreatingEvent(object: $this->entity());
		$this->listener->handle($creating);

		$this->assertTrue($creating->isPropagationStopped());
		$errors = $creating->getErrors();
		$this->assertStringContainsString('500 cases per month', $errors['message']);
		$this->assertStringContainsString('basic', $errors['message']);
		$this->assertSame(500, $errors['limit']);
		$this->assertSame('cases_per_month', $errors['quotaType']);
	}//end testPastTheHardLimitTheCreateIsRefused()

	/**
	 * A quota enforced as warn lets the create through past the limit.
	 *
	 * @return void
	 */
	public function testAWarnQuotaLetsTheCreateThrough(): void {
		$this->quota['currentUsage'] = 500;
		$this->quota['enforcement'] = 'warn';

		$creating = new ObjectCreatingEvent(object: $this->entity());
		$this->listener->handle($creating);

		$this->assertFalse($creating->isPropagationStopped());
	}//end testAWarnQuotaLetsTheCreateThrough()

	/**
	 * A user added to a full tenant is refused on the users quota.
	 *
	 * @return void
	 */
	public function testAUserPastTheUsersLimitIsRefused(): void {
		$this->quota['quotaType'] = 'active_users';
		$this->quota['limit'] = 5;
		$this->quota['currentUsage'] = 5;

		$creating = new ObjectCreatingEvent(object: $this->entity(schema: 'tenantUser'));
		$this->listener->handle($creating);

		$this->assertTrue($creating->isPropagationStopped());
		$this->assertStringContainsString('5 users', $creating->getErrors()['message']);
	}//end testAUserPastTheUsersLimitIsRefused()

	/**
	 * A schema that consumes no quota is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotCounted(): void {
		$this->quota['currentUsage'] = 500;

		$creating = new ObjectCreatingEvent(object: $this->entity(schema: 'note'));
		$this->listener->handle($creating);
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schema: 'note')));

		$this->assertFalse($creating->isPropagationStopped());
		$this->assertSame(500, $this->quota['currentUsage']);
	}//end testAnotherSchemaIsNotCounted()

	/**
	 * A request without a tenant is neither refused nor counted.
	 *
	 * @return void
	 */
	public function testARequestWithoutATenantIsNotCounted(): void {
		$this->bound = false;
		$this->quota['currentUsage'] = 500;

		$creating = new ObjectCreatingEvent(object: $this->entity());
		$this->listener->handle($creating);
		$this->listener->handle(new ObjectCreatedEvent($this->entity()));

		$this->assertFalse($creating->isPropagationStopped());
		$this->assertSame(500, $this->quota['currentUsage']);
	}//end testARequestWithoutATenantIsNotCounted()

	/**
	 * Crossing the soft limit notifies the tenant administrators once.
	 *
	 * @return void
	 */
	public function testCrossingTheSoftLimitNotifiesTheAdministratorsOnce(): void {
		$this->quota['currentUsage'] = 398;

		$this->listener->handle(new ObjectCreatedEvent($this->entity()));
		$this->assertSame([], $this->sent);

		$this->listener->handle(new ObjectCreatedEvent($this->entity()));
		$this->listener->handle(new ObjectCreatedEvent($this->entity()));

		$this->assertCount(1, $this->sent);
		$this->assertSame('beheer', $this->sent[0]['user']);
		$this->assertSame(Notifier::SUBJECT_QUOTA_SOFT_LIMIT, $this->sent[0]['subject']);
		$this->assertSame(['quotaType' => 'cases_per_month', 'used' => 400, 'limit' => 500], $this->sent[0]['params']);
	}//end testCrossingTheSoftLimitNotifiesTheAdministratorsOnce()

	/**
	 * A created object of the given schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema = 'case'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn([
			'title' => 'Aanvraag',
			'@self' => ['id' => '22222222-2222-4222-8222-222222222222', 'schema' => $schema],
		]);

		return $entity;
	}//end entity()
}//end class
