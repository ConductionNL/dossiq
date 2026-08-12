<?php

/**
 * CaseTransferService Federation Unit Tests
 *
 * Covers the federated-case-collaboration additions: idempotent federated
 * initiate, transfer-scoped OR token minting, remote token resolution
 * (direction/status/permissions/objectUri checks), the accept/reject state
 * machine's idempotency + loud-refusal-on-ambiguity, custody audit trail
 * accumulation, and the pre-existing `handleTransfer` authorization gap fix
 * (via {@see \OCA\Procest\Service\CaseTransferService::getCaseIdForTransfer()}).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\CaseTransferService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TenantAuditTrailService;
use OCA\Procest\Service\Transfer\TransferRegisterGateway;
use OCA\Procest\Service\Transfer\TransferShareBroker;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake "saved object" — implements the jsonSerialize()/getUuid() shape the
 * real OR ObjectService::saveObject() return value carries.
 */
final class CtfFakeTransferObject implements \JsonSerializable {
	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		private array $data,
	) {
	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}//end jsonSerialize()

	public function getUuid(): string {
		return (string)($this->data['id'] ?? '');
	}//end getUuid()
}//end class

/**
 * Hand-written fake OpenRegister ObjectService for CaseTransferService.
 */
final class CtfFakeObjectService {
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
	 * @return CtfFakeTransferObject
	 */
	public function saveObject(array $object, int $register, int $schema, ?string $uuid = null) {
		$id = $object['id'] ?? $uuid ?? ('transfer-' . $this->autoId++);
		$object['id'] = $id;
		$this->store[(string)$id] = $object;
		return new CtfFakeTransferObject($object);
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
 * Fake OR FederationShareService (createOutgoingShare only, for the
 * transfer-scoped share).
 */
final class CtfFakeFederationShareService {
	/** @var array<int, array<string, mixed>> */
	public array $created = [];

	private int $autoId = 200;

	/**
	 * @param array<string, mixed> $params
	 */
	public function createOutgoingShare(array $params): CtfFakeFederatedShare {
		$id = $this->autoId++;
		$this->created[$id] = $params;
		return new CtfFakeFederatedShare($id);
	}//end createOutgoingShare()
}//end class

/**
 * Minimal fake FederatedShare entity — getId() only needed by the minting path.
 */
final class CtfFakeFederatedShare {
	public function __construct(
		private int $id,
	) {
	}//end __construct()

	public function getId(): int {
		return $this->id;
	}//end getId()
}//end class

/**
 * Fake FederatedShare "row" as returned by findByToken() — carries every
 * getter {@see \OCA\Procest\Service\CaseTransferService::resolveFederatedTransferShare()} reads.
 */
final class CtfFakeFederatedShareRow {
	public function __construct(
		private string $direction,
		private string $status,
		private string $permissions,
		private string $objectUri,
		private string $sharedWith,
		private ?string $organisation = null,
	) {
	}//end __construct()

	public function getDirection(): string {
		return $this->direction;
	}//end getDirection()

	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	public function getPermissions(): string {
		return $this->permissions;
	}//end getPermissions()

	public function getObjectUri(): string {
		return $this->objectUri;
	}//end getObjectUri()

	public function getSharedWith(): string {
		return $this->sharedWith;
	}//end getSharedWith()

	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()
}//end class

/**
 * Fake OR FederatedShareMapper — findByToken() keyed lookup.
 */
final class CtfFakeFederatedShareMapper {
	/** @var array<string, CtfFakeFederatedShareRow> */
	public array $byToken = [];

	public function findByToken(string $shareToken): CtfFakeFederatedShareRow {
		if (isset($this->byToken[$shareToken]) === false) {
			throw new \RuntimeException('not found');
		}

		return $this->byToken[$shareToken];
	}//end findByToken()
}//end class

/**
 * @covers \OCA\Procest\Service\CaseTransferService
 *
 * @uses \OCA\Procest\Service\Transfer\TransferRegisterGateway
 * @uses \OCA\Procest\Service\Transfer\TransferShareBroker
 */
class CaseTransferServiceFederationTest extends TestCase {
	private CtfFakeObjectService $objects;

	private CtfFakeFederationShareService $federation;

	private CtfFakeFederatedShareMapper $mapper;

	private CaseTransferService $service;

	/**
	 * Assemble CaseTransferService with real transfer collaborators.
	 *
	 * The register gateway and share broker are real objects rather than
	 * mocks: every assertion in this class is about behaviour they inherited
	 * verbatim from CaseTransferService, and they stay driven entirely by the
	 * mocked app manager and container passed in here.
	 *
	 * @param SettingsService $settings Settings service (mock).
	 * @param IAppManager $appManager App manager (mock).
	 * @param ContainerInterface $container DI container (mock).
	 * @param LoggerInterface $logger Logger (mock).
	 * @param TenantAuditTrailService $auditTrail Audit trail (mock).
	 *
	 * @return CaseTransferService
	 */
	private static function makeTransferService(
		SettingsService $settings,
		IAppManager $appManager,
		ContainerInterface $container,
		LoggerInterface $logger,
		TenantAuditTrailService $auditTrail,
	): CaseTransferService {
		$gateway = new TransferRegisterGateway($appManager, $container, $logger);

		return new CaseTransferService(
			settingsService: $settings,
			gateway: $gateway,
			shareBroker: new TransferShareBroker($gateway, $logger),
			logger: $logger,
			auditTrail: $auditTrail,
		);
	}//end makeTransferService()

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new CtfFakeObjectService();
		$this->federation = new CtfFakeFederationShareService();
		$this->mapper = new CtfFakeFederatedShareMapper();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) {
				return match ($key) {
					'register' => '1',
					'case_transfer_schema' => '5',
					default => '',
				};
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $this->objects,
					'OCA\OpenRegister\Service\FederationShareService' => $this->federation,
					'OCA\OpenRegister\Db\FederatedShareMapper' => $this->mapper,
					default => throw new \RuntimeException('unexpected container lookup: ' . $id),
				};
			}
		);

		$this->service = self::makeTransferService(
			$settings,
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TenantAuditTrailService::class),
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testInitiatingTheSameFederatedTransferTwiceIsIdempotent(): void {
		$first = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'joint enforcement', '2026-08-01', 'alice', 'partner@remote.example');
		self::assertArrayNotHasKey('error', $first);
		self::assertCount(1, $this->objects->store);
		self::assertCount(1, $this->federation->created);

		$second = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'joint enforcement', '2026-08-01', 'alice', 'partner@remote.example');

		self::assertSame($first['id'], $second['id']);
		self::assertCount(1, $this->objects->store, 'No second transfer object should be created');
		self::assertCount(1, $this->federation->created, 'No second OR share should be minted');
	}//end testInitiatingTheSameFederatedTransferTwiceIsIdempotent()

	/**
	 * @return void
	 */
	public function testInitiateFederatedTransferMintsReadWriteTransferScopedShare(): void {
		$result = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice', 'partner@remote.example');

		self::assertCount(1, $this->federation->created);
		$params = array_values($this->federation->created)[0];
		self::assertSame('read-write', $params['permissions']);
		self::assertSame($result['id'], $params['objectUri']);
	}//end testInitiateFederatedTransferMintsReadWriteTransferScopedShare()

	/**
	 * @return void
	 */
	public function testLocalTransferHasNoFederatedShareMinted(): void {
		$this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice');

		self::assertCount(0, $this->federation->created);
	}//end testLocalTransferHasNoFederatedShareMinted()

	/**
	 * @return void
	 */
	public function testRemoteAcceptsTransferViaValidScopedToken(): void {
		$transfer = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice', 'partner@remote.example');
		$transferId = (string)$transfer['id'];

		$this->mapper->byToken['tok-good'] = new CtfFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'accepted',
			permissions: 'read-write',
			objectUri: $transferId,
			sharedWith: 'partner@remote.example',
		);

		$resolved = $this->service->resolveFederatedTransferShare('tok-good', $transferId);
		self::assertNotNull($resolved);
		self::assertSame('partner@remote.example', $resolved['sharedWith']);

		$result = $this->service->acceptTransfer($transferId, $resolved['sharedWith']);
		self::assertSame('accepted', $result['status']);

		$trail = $result['custodyAuditTrail'];
		self::assertSame('accepted', end($trail)['event']);
		self::assertSame('remote', end($trail)['actorType']);
		self::assertSame('partner@remote.example', end($trail)['cloudId']);
	}//end testRemoteAcceptsTransferViaValidScopedToken()

	/**
	 * @return void
	 */
	public function testReadOnlyCaseShareTokenCannotAcceptATransfer(): void {
		$transfer = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice', 'partner@remote.example');
		$transferId = (string)$transfer['id'];

		// A read-only case-summary share token (permissions: 'read'), not the
		// transfer-scoped read-write token.
		$this->mapper->byToken['tok-readonly'] = new CtfFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'accepted',
			permissions: 'read',
			objectUri: $transferId,
			sharedWith: 'partner@remote.example',
		);

		self::assertNull($this->service->resolveFederatedTransferShare('tok-readonly', $transferId));
	}//end testReadOnlyCaseShareTokenCannotAcceptATransfer()

	/**
	 * @return void
	 */
	public function testTokenMintedForADifferentTransferCannotBeReplayed(): void {
		$transferA = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice', 'partner@remote.example');
		$transferB = $this->service->initiateTransfer('case-2', 'org-a', 'org-c', 'reason', '2026-08-01', 'alice', 'partner2@remote.example');

		$this->mapper->byToken['tok-a'] = new CtfFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'accepted',
			permissions: 'read-write',
			objectUri: (string)$transferA['id'],
			sharedWith: 'partner@remote.example',
		);

		// Token for transfer A replayed against transfer B — must fail.
		self::assertNull($this->service->resolveFederatedTransferShare('tok-a', (string)$transferB['id']));
	}//end testTokenMintedForADifferentTransferCannotBeReplayed()

	/**
	 * @return void
	 */
	public function testRevokedTokenCannotAuthenticateAnAccept(): void {
		$transfer = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice', 'partner@remote.example');
		$transferId = (string)$transfer['id'];

		$this->mapper->byToken['tok-revoked'] = new CtfFakeFederatedShareRow(
			direction: 'outgoing',
			status: 'revoked',
			permissions: 'read-write',
			objectUri: $transferId,
			sharedWith: 'partner@remote.example',
		);

		self::assertNull($this->service->resolveFederatedTransferShare('tok-revoked', $transferId));
	}//end testRevokedTokenCannotAuthenticateAnAccept()

	/**
	 * @return void
	 */
	public function testAcceptingAnAlreadyRejectedTransferIsRefusedLoudly(): void {
		$transfer = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice');
		$transferId = (string)$transfer['id'];

		$rejected = $this->service->rejectTransfer($transferId, 'no capacity');
		self::assertSame('rejected', $rejected['status']);

		$result = $this->service->acceptTransfer($transferId);

		self::assertArrayHasKey('error', $result);
		self::assertSame('rejected', $this->objects->store[$transferId]['status'], 'Status must remain unchanged after the refused call');
	}//end testAcceptingAnAlreadyRejectedTransferIsRefusedLoudly()

	/**
	 * @return void
	 */
	public function testRepeatingAnAcceptCallAfterItAlreadySucceededIsASafeNoOp(): void {
		$transfer = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice');
		$transferId = (string)$transfer['id'];

		$first = $this->service->acceptTransfer($transferId);
		$trailAfterFirst = count($first['custodyAuditTrail']);

		$second = $this->service->acceptTransfer($transferId);

		self::assertSame('accepted', $second['status']);
		self::assertCount($trailAfterFirst, $second['custodyAuditTrail'], 'No duplicate audit entry on idempotent replay');
	}//end testRepeatingAnAcceptCallAfterItAlreadySucceededIsASafeNoOp()

	/**
	 * @return void
	 */
	public function testCustodyAuditTrailAccumulatesAcrossInitiateAndAccept(): void {
		$transfer = $this->service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice');
		$transferId = (string)$transfer['id'];
		self::assertCount(1, $transfer['custodyAuditTrail']);
		self::assertSame('initiated', $transfer['custodyAuditTrail'][0]['event']);

		$accepted = $this->service->acceptTransfer($transferId);
		self::assertCount(2, $accepted['custodyAuditTrail']);
		self::assertSame('accepted', $accepted['custodyAuditTrail'][1]['event']);
		self::assertSame('local', $accepted['custodyAuditTrail'][1]['actorType']);
	}//end testCustodyAuditTrailAccumulatesAcrossInitiateAndAccept()

	/**
	 * @return void
	 */
	public function testGetCaseIdForTransferResolvesTheOwningCase(): void {
		$transfer = $this->service->initiateTransfer('case-42', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice');

		self::assertSame('case-42', $this->service->getCaseIdForTransfer((string)$transfer['id']));
	}//end testGetCaseIdForTransferResolvesTheOwningCase()

	/**
	 * @return void
	 */
	public function testGetCaseIdForTransferReturnsNullForUnknownTransfer(): void {
		self::assertNull($this->service->getCaseIdForTransfer('does-not-exist'));
	}//end testGetCaseIdForTransferReturnsNullForUnknownTransfer()

	/**
	 * @return void
	 */
	public function testInitiateFederatedTransferFailsClosedWhenOrFederationLeafUnavailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('1');

		$objects = new CtfFakeObjectService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				throw new \RuntimeException('federation leaf not installed');
			}
		);

		$service = self::makeTransferService(
			$settings,
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TenantAuditTrailService::class),
		);

		$result = $service->initiateTransfer('case-1', 'org-a', 'org-b', 'reason', '2026-08-01', 'alice', 'partner@remote.example');

		self::assertArrayHasKey('error', $result);
		self::assertCount(0, $objects->store, 'No transfer object should be persisted when the federation leaf is unavailable');
	}//end testInitiateFederatedTransferFailsClosedWhenOrFederationLeafUnavailable()
}//end class
