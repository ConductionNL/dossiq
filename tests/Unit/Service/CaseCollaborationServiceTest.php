<?php

/**
 * CaseCollaborationService Unit Tests
 *
 * Covers the federated case-share async, append-only activity stream: local
 * (session-authenticated) posting, remote (bearer-token-authenticated)
 * posting, revoked/mismatched-token rejection, and fail-closed behaviour
 * when the OR federation leaf is unavailable.
 *
 * @category Tests
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
 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseCollaborationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hand-written fake OpenRegister ObjectService for CaseCollaborationService.
 */
final class CcaFakeObjectService {
	/** @var array<string, array<string, mixed>> */
	public array $store = [];

	private int $autoId = 1;

	/**
	 * @param int|string $id
	 * @param mixed ...$args
	 *
	 * @return array<string, mixed>|null
	 */
	public function find($id, ...$args) {
		return $this->store[(string)$id] ?? null;
	}//end find()

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, int $register, int $schema, ?string $uuid = null) {
		$id = $object['id'] ?? $uuid ?? ('activity-' . $this->autoId++);
		$object['id'] = $id;
		$this->store[(string)$id] = $object;
		return $object;
	}//end saveObject()

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config) {
		$filters = $config['filters'] ?? [];
		$results = [];
		foreach ($this->store as $item) {
			$matches = true;
			foreach ($filters as $key => $value) {
				if (in_array($key, ['register', 'schema'], true) === true) {
					continue;
				}

				if (($item[$key] ?? null) !== $value) {
					$matches = false;
					break;
				}
			}

			if ($matches === true) {
				$results[] = $item;
			}
		}

		return $results;
	}//end findAll()
}//end class

/**
 * Fake FederatedShare "row" as returned by findByToken().
 */
final class CcaFakeFederatedShareRow {
	public function __construct(
		private string $direction,
		private string $status,
		private string $objectUri,
		private string $sharedWith,
	) {
	}//end __construct()

	public function getDirection(): string {
		return $this->direction;
	}//end getDirection()

	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	public function getObjectUri(): string {
		return $this->objectUri;
	}//end getObjectUri()

	public function getSharedWith(): string {
		return $this->sharedWith;
	}//end getSharedWith()
}//end class

/**
 * Fake OR FederatedShareMapper — findByToken() keyed lookup.
 */
final class CcaFakeFederatedShareMapper {
	/** @var array<string, CcaFakeFederatedShareRow> */
	public array $byToken = [];

	public function findByToken(string $shareToken): CcaFakeFederatedShareRow {
		if (isset($this->byToken[$shareToken]) === false) {
			throw new \RuntimeException('not found');
		}

		return $this->byToken[$shareToken];
	}//end findByToken()
}//end class

/**
 * @covers \OCA\Dossiq\Service\CaseCollaborationService
 */
class CaseCollaborationServiceTest extends TestCase {
	private CcaFakeObjectService $objects;

	private CcaFakeFederatedShareMapper $mapper;

	private CaseCollaborationService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new CcaFakeObjectService();
		$this->mapper = new CcaFakeFederatedShareMapper();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) {
				return match ($key) {
					'register' => '1',
					'case_federated_share_schema' => '3',
					'case_federated_activity_schema' => '4',
					default => '',
				};
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $this->objects,
					'OCA\OpenRegister\Db\FederatedShareMapper' => $this->mapper,
					default => throw new \RuntimeException('unexpected container lookup: ' . $id),
				};
			}
		);

		$this->service = new CaseCollaborationService(
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			tenantAuditTrail: $this->createMock(TenantAuditTrailService::class),
		);

		// The caseFederatedShare this activity stream is scoped to.
		$this->objects->store['share-1'] = [
			'id' => 'share-1',
			'caseId' => 'case-1',
			'status' => 'active',
		];
	}//end setUp()

	/**
	 * @return void
	 */
	public function testLocalHandlerPostsAnActivityEntry(): void {
		$result = $this->service->postLocalActivity('share-1', 'alice', 'Please review the enforcement letter');

		self::assertArrayNotHasKey('error', $result);
		self::assertCount(1, $result['entries']);
		self::assertSame('local', $result['entries'][0]['actorType']);
		self::assertSame('alice', $result['entries'][0]['actor']);
	}//end testLocalHandlerPostsAnActivityEntry()

	/**
	 * @return void
	 */
	public function testRemoteOrgPostsAnActivityEntryViaItsScopedToken(): void {
		$this->mapper->byToken['tok-good'] = new CcaFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'accepted',
			objectUri: 'share-1',
			sharedWith: 'partner@remote.example',
		);

		$result = $this->service->postRemoteActivity('tok-good', 'share-1', 'Received, will follow up');

		self::assertArrayNotHasKey('error', $result);
		self::assertCount(1, $result['entries']);
		self::assertSame('remote', $result['entries'][0]['actorType']);
		self::assertSame('partner@remote.example', $result['entries'][0]['cloudId']);
	}//end testRemoteOrgPostsAnActivityEntryViaItsScopedToken()

	/**
	 * @return void
	 */
	public function testActivityEntriesAreAppendedNeverRewritten(): void {
		$this->service->postLocalActivity('share-1', 'alice', 'first');
		$this->service->postLocalActivity('share-1', 'bob', 'second');

		$entries = $this->service->listActivity('share-1');
		self::assertCount(2, $entries);
		self::assertSame('first', $entries[0]['message']);
		self::assertSame('second', $entries[1]['message']);
	}//end testActivityEntriesAreAppendedNeverRewritten()

	/**
	 * @return void
	 */
	public function testARevokedShareCannotAcceptANewActivityPost(): void {
		$this->objects->store['share-1']['status'] = 'revoked';

		$result = $this->service->postLocalActivity('share-1', 'alice', 'too late');
		self::assertArrayHasKey('error', $result);
	}//end testARevokedShareCannotAcceptANewActivityPost()

	/**
	 * @return void
	 */
	public function testRevokedRemoteTokenCannotPostActivity(): void {
		$this->mapper->byToken['tok-revoked'] = new CcaFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'revoked',
			objectUri: 'share-1',
			sharedWith: 'partner@remote.example',
		);

		$result = $this->service->postRemoteActivity('tok-revoked', 'share-1', 'too late');
		self::assertArrayHasKey('error', $result);
		self::assertCount(0, $this->service->listActivity('share-1'));
	}//end testRevokedRemoteTokenCannotPostActivity()

	/**
	 * @return void
	 */
	public function testTokenMintedForADifferentShareCannotPostHere(): void {
		$this->objects->store['share-2'] = ['id' => 'share-2', 'caseId' => 'case-2', 'status' => 'active'];

		// Token's objectUri points at share-2, but the caller claims share-1.
		$this->mapper->byToken['tok-mismatch'] = new CcaFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'accepted',
			objectUri: 'share-2',
			sharedWith: 'partner@remote.example',
		);

		$result = $this->service->postRemoteActivity('tok-mismatch', 'share-1', 'sneaky');
		self::assertArrayHasKey('error', $result);
	}//end testTokenMintedForADifferentShareCannotPostHere()

	/**
	 * @return void
	 */
	public function testListRemoteActivityRequiresAValidToken(): void {
		$this->service->postLocalActivity('share-1', 'alice', 'internal note visible to remote too');

		$result = $this->service->listRemoteActivity('tok-unknown', 'share-1');
		self::assertArrayHasKey('error', $result);
	}//end testListRemoteActivityRequiresAValidToken()

	/**
	 * @return void
	 */
	public function testListRemoteActivitySucceedsWithAValidToken(): void {
		$this->service->postLocalActivity('share-1', 'alice', 'hello');
		$this->mapper->byToken['tok-good'] = new CcaFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'accepted',
			objectUri: 'share-1',
			sharedWith: 'partner@remote.example',
		);

		$result = $this->service->listRemoteActivity('tok-good', 'share-1');
		self::assertArrayNotHasKey('error', $result);
		self::assertCount(1, $result['entries']);
	}//end testListRemoteActivitySucceedsWithAValidToken()

	/**
	 * @return void
	 */
	public function testPostLocalActivityFailsClosedWhenOrUnavailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		$service = new CaseCollaborationService(
			settingsService: $this->createMock(SettingsService::class),
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			tenantAuditTrail: $this->createMock(TenantAuditTrailService::class),
		);

		$result = $service->postLocalActivity('share-1', 'alice', 'hello');
		self::assertArrayHasKey('error', $result);
	}//end testPostLocalActivityFailsClosedWhenOrUnavailable()
}//end class
