<?php

/**
 * Builds the real BezwaarAuditTrail over one shared in-memory store.
 *
 * The bezwaar services save through SettingsService's ObjectService, and the
 * audit writer resolves OpenRegister's ObjectService and AuditTrailMapper from
 * the container. Here both read the same InMemoryRegister, so an entry can
 * only be written for a record that was really saved, and a deleted record
 * really is gone. Only the OpenRegister seams are doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder;
use OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner;
use OCA\Dossiq\Service\Bezwaar\HearingService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

trait MakesBezwaarAuditTrail {
	/**
	 * The shared object store.
	 *
	 * @var RefusableRegister
	 */
	private RefusableRegister $store;

	/**
	 * The audit rows written.
	 *
	 * @var RecordingAuditTrailMapper
	 */
	private RecordingAuditTrailMapper $trail;

	/**
	 * Every error logged by the audit writer, message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $auditErrors = [];

	/**
	 * Start an empty store and trail.
	 *
	 * @return void
	 */
	private function startBezwaarStore(): void {
		$this->store = new RefusableRegister();
		$this->trail = new RecordingAuditTrailMapper();
		$this->auditErrors = [];
	}//end startBezwaarStore()

	/**
	 * The real audit writer.
	 *
	 * @param string|null $uid          The signed-in uid, or null for no session.
	 * @param bool        $openRegister Whether OpenRegister is installed.
	 *
	 * @return BezwaarAuditTrail The writer.
	 */
	private function bezwaarAuditTrail(?string $uid = 'handler-1', bool $openRegister = true): BezwaarAuditTrail {
		$users = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$users->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users->method('getUser')->willReturn($user);
		}

		$installed = [];
		if ($openRegister === true) {
			$installed = ['openregister'];
		}

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		$objects = new EntityAnsweringRegister(register: $this->store);
		$trail = $this->trail;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $trail): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\ObjectService' => $objects,
					'OCA\\OpenRegister\\Db\\AuditTrailMapper' => $trail,
					default => throw new RuntimeException('unknown service '.$id),
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->auditErrors[] = [(string) $message, $context];
			}
		);

		return new BezwaarAuditTrail(userSession: $users, appManager: $apps, container: $container, logger: $logger);
	}//end bezwaarAuditTrail()

	/**
	 * A SettingsService over the shared store, with the bezwaar schemas configured.
	 *
	 * @return SettingsService The settings.
	 */
	private function bezwaarSettings(): SettingsService {
		$config = [
			'register' => 'dossiq',
			'hearing_session_schema' => 'hearingSession',
			'bezwaar_schema' => 'objectionProceeding',
			'bac_advice_request_schema' => 'bacAdviceRequest',
			'bezwaaradviescommissie_schema' => 'bezwaaradviescommissie',
			'bac_default_committee' => 'committee-1',
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, mixed ...$rest): string => (string) ($config[$key] ?? '')
		);

		return $settings;
	}//end bezwaarSettings()

	/**
	 * The real HearingService over the shared store and the given audit writer.
	 *
	 * @param BezwaarAuditTrail       $trail      The audit writer.
	 * @param OwningCaseResolver|null $owningCase Resolves a session's case, or null for a double that resolves none.
	 *
	 * @return HearingService The service.
	 */
	private function realHearingService(BezwaarAuditTrail $trail, ?OwningCaseResolver $owningCase = null): HearingService {
		$logger = $this->createMock(LoggerInterface::class);

		return new HearingService(
			settingsService: $this->bezwaarSettings(),
			logger: $logger,
			auditTrail: $trail,
			planner: new HearingSchedulePlanner(),
			minutes: new HearingMinutesRecorder(auditTrail: $trail),
			owningCase: $owningCase ?? $this->createMock(OwningCaseResolver::class),
		);
	}//end realHearingService()

	/**
	 * The context of the one row with this action on this record.
	 *
	 * @param string $uuid   The record.
	 * @param string $action The action.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function rowContext(string $uuid, string $action): array {
		$matches = array_values(array_filter(
			$this->trail->rows,
			static fn (array $row): bool => $row['object'] === $uuid && $row['action'] === $action
		));
		$this->assertCount(1, $matches, 'expected exactly one '.$action.' row on '.$uuid);

		return $matches[0]['context'];
	}//end rowContext()
}//end trait
