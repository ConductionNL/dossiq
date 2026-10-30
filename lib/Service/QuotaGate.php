<?php

/**
 * Dossiq Quota Gate
 *
 * Checks and counts a tenant's quota at the writes that consume it. A write
 * past a blocking or throttling limit is refused with the limit and the tier;
 * the write that crosses the soft limit notifies the tenant's administrators.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Notification\Notifier;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuse a write past the tenant's quota, count it once it is saved.
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */
class QuotaGate {
	/**
	 * The quota each counted schema consumes, by schema slug.
	 *
	 * @var array<string, string>
	 */
	public const SCHEMA_QUOTAS = [
		'case' => 'cases_per_month',
		'tenantUser' => 'active_users',
	];

	/**
	 * The role whose holders get the soft limit notice.
	 */
	private const ADMIN_ROLE = 'tenant_admin';

	/**
	 * Constructor.
	 *
	 * @param TenantQuotaService        $quotas        Reads, decides and counts quota rows.
	 * @param TenantContext             $tenant        The request's tenant.
	 * @param SettingsService           $settings      Reaches OpenRegister's ObjectService.
	 * @param INotificationManager      $notifications Sends the soft limit notice.
	 * @param IL10N                     $l10n          Words the refusal.
	 * @param LoggerInterface           $logger        Logger.
	 * @param QuotaLimitLabel           $labels        Words the limit.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row as an array.
	 */
	public function __construct(
		private readonly TenantQuotaService $quotas,
		private readonly TenantContext $tenant,
		private readonly SettingsService $settings,
		private readonly INotificationManager $notifications,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly QuotaLimitLabel $labels = new QuotaLimitLabel(),
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * The quota a schema's create consumes, or null when it consumes none.
	 *
	 * @param string $schemaSlug The schema slug of the created object.
	 *
	 * @return string|null The quota type.
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function quotaTypeFor(string $schemaSlug): ?string {
		return self::SCHEMA_QUOTAS[$schemaSlug] ?? null;
	}//end quotaTypeFor()

	/**
	 * The refusal for a write that would pass a blocking limit, or null to let it through.
	 *
	 * A request without a tenant (a single-organisation install, a background
	 * job) is not counted. A quota enforced as `warn` lets the write through.
	 *
	 * @param string $quotaType The quota the write consumes.
	 * @param int    $amount    How much it consumes.
	 *
	 * @return array{message:string, quotaType:string, limit:int, tier:string}|null
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function refusal(string $quotaType, int $amount = 1): ?array {
		if ($this->tenant->isBound() === false) {
			return null;
		}

		$quota = $this->quotas->getQuota(tenantId: $this->tenant->getTenantId(), quotaType: $quotaType);
		if ($quota === null) {
			return null;
		}

		$decision = $this->quotas->decide(quota: $quota, increment: $amount);
		$refused  = [TenantQuotaService::DECISION_BLOCK, TenantQuotaService::DECISION_THROTTLE];
		if (in_array($decision['decision'], $refused, true) === false) {
			return null;
		}

		$limit = (int)($quota['limit'] ?? 0);
		$tier  = (string)($this->tenant->getTenant()['tier'] ?? '');

		return [
			'message' => $this->l10n->t(
				'Your organisation reached its limit of %1$s on the %2$s plan.',
				[$this->labels->label(l: $this->l10n, quotaType: $quotaType, limit: $limit), $tier]
			),
			'quotaType' => $quotaType,
			'limit' => $limit,
			'tier' => $tier,
		];
	}//end refusal()

	/**
	 * Count a saved write and notify the administrators when it crosses the soft limit.
	 *
	 * The notice goes out on the one write that moves usage from below the
	 * soft limit to at or above it, so it is sent once per period: usage
	 * only falls back below the soft limit when the period resets.
	 *
	 * @param string $quotaType The quota the write consumed.
	 * @param int    $amount    How much it consumed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function record(string $quotaType, int $amount = 1): void {
		if ($this->tenant->isBound() === false) {
			return;
		}

		$tenantId = $this->tenant->getTenantId();
		$before   = $this->quotas->getQuota(tenantId: $tenantId, quotaType: $quotaType);
		$result   = $this->quotas->consume(tenantId: $tenantId, quotaType: $quotaType, amount: $amount);
		if ($before === null || isset($result['currentUsage']) === false || ($before['limit'] ?? null) === null) {
			return;
		}

		$limit     = (int)$before['limit'];
		$softLimit = (int)floor($limit * ((int)($before['softLimitWarningPercent'] ?? 80) / 100));
		$previous  = (int)($before['currentUsage'] ?? 0);
		if ($previous >= $softLimit || $result['currentUsage'] < $softLimit) {
			return;
		}

		$this->notifyAdmins(tenantId: $tenantId, quotaType: $quotaType, used: $result['currentUsage'], limit: $limit);
	}//end record()

	/**
	 * Send the soft limit notice to every administrator of the tenant.
	 *
	 * @param string $tenantId  The tenant.
	 * @param string $quotaType The quota type.
	 * @param int    $used      Usage after the write.
	 * @param int    $limit     The hard limit.
	 *
	 * @return void
	 */
	private function notifyAdmins(string $tenantId, string $quotaType, int $used, int $limit): void {
		try {
			$admins = $this->adminsOf(tenantId: $tenantId);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: tenant administrators lookup failed, no soft quota notice sent', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($admins as $userId) {
			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($userId)
					->setDateTime(new DateTime())
					->setObject('tenant', $tenantId)
					->setSubject(
						Notifier::SUBJECT_QUOTA_SOFT_LIMIT,
						['quotaType' => $quotaType, 'used' => $used, 'limit' => $limit]
					);
				$this->notifications->notify($notification);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: soft quota notice failed',
					['tenantId' => $tenantId, 'user' => $userId, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end notifyAdmins()

	/**
	 * The user ids holding the administrator role on the tenant.
	 *
	 * @param string $tenantId The tenant.
	 *
	 * @return list<string>
	 */
	private function adminsOf(string $tenantId): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$rows = $objectService->findAll(
			[
				'filters' => [
					'register' => Application::REGISTER_SLUG,
					'schema' => 'tenantUser',
					'tenantRef' => $tenantId,
					'role' => self::ADMIN_ROLE,
				],
			]
		);

		$admins = [];
		foreach ((array)$rows as $row) {
			$data   = $this->rowNormaliser->normalise(row: $row)['data'];
			$userId = (string)($data['userRef'] ?? '');
			if ($userId !== '' && ($data['role'] ?? '') === self::ADMIN_ROLE) {
				$admins[] = $userId;
			}
		}

		return array_values(array_unique($admins));
	}//end adminsOf()
}//end class
