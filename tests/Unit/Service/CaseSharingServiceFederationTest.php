<?php

/**
 * CaseSharingService Federation Unit Tests
 *
 * Covers the federated-case-collaboration additions: field/document
 * allow-list enforcement, snapshot redaction (never `@self`/`relations`),
 * OR federation-leaf token minting + revocation, fail-closed behaviour when
 * the leaf is unavailable, and audit-trail emission.
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

use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Sharing\CaseAccessPolicy;
use OCA\Procest\Service\Sharing\CaseTokenShareService;
use OCA\Procest\Service\Sharing\FederatedCaseShareService;
use OCA\Procest\Service\Sharing\OpenRegisterSharingGateway;
use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hand-written fake OpenRegister ObjectService — real named parameters (not
 * a PHPUnit mock) so calls like find(id, register: .., schema: ..) and
 * saveObject(object: .., register: .., schema: ..) resolve exactly like the
 * real service.
 */
final class CsfFakeObjectService {
	/** @var array<string, array<string, mixed>> */
	public array $objects = [];

	private int $autoId = 1;

	/**
	 * @param int|string $id
	 * @param mixed ...$args
	 *
	 * @return array<string, mixed>|null
	 */
	public function find($id, ...$args) {
		return $this->objects[(string)$id] ?? null;
	}//end find()

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, int $register, int $schema, ?string $uuid = null) {
		$id = $object['id'] ?? $uuid ?? ('fake-' . $this->autoId++);
		$object['id'] = $id;
		$this->objects[(string)$id] = $object;
		return $object;
	}//end saveObject()

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config) {
		return [];
	}//end findAll()
}//end class

/**
 * Hand-written fake of OR's FederationShareService.
 */
final class CsfFakeFederationShareService {
	/** @var array<int, array<string, mixed>> */
	public array $created = [];

	/** @var array<int, string> */
	public array $statusChanges = [];

	private int $autoId = 100;

	public bool $throwOnCreate = false;

	/**
	 * @param array<string, mixed> $params
	 *
	 * @return CsfFakeFederatedShare
	 */
	public function createOutgoingShare(array $params) {
		if ($this->throwOnCreate === true) {
			throw new \RuntimeException('OR unavailable');
		}

		$id = $this->autoId++;
		$this->created[$id] = $params;
		return new CsfFakeFederatedShare($id, $params);
	}//end createOutgoingShare()

	/**
	 * @return CsfFakeFederatedShare
	 */
	public function setStatus(int $id, string $status) {
		$this->statusChanges[$id] = $status;
		return new CsfFakeFederatedShare($id, ['status' => $status]);
	}//end setStatus()
}//end class

/**
 * Minimal fake of OR's FederatedShare entity — only the getters this
 * change's code actually calls.
 */
final class CsfFakeFederatedShare {
	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		private int $id,
		private array $data,
	) {
	}//end __construct()

	public function getId(): int {
		return $this->id;
	}//end getId()
}//end class

/**
 * @covers \OCA\Procest\Service\CaseSharingService
 *
 * @uses \OCA\Procest\Service\Sharing\CaseAccessPolicy
 * @uses \OCA\Procest\Service\Sharing\CaseTokenShareService
 * @uses \OCA\Procest\Service\Sharing\FederatedCaseShareService
 * @uses \OCA\Procest\Service\Sharing\OpenRegisterSharingGateway
 */
class CaseSharingServiceFederationTest extends TestCase {
	private CsfFakeObjectService $objects;

	private CsfFakeFederationShareService $federation;

	private ContainerInterface $container;

	private TenantAuditTrailService $audit;

	private CaseSharingService $service;

	/**
	 * Assemble CaseSharingService with real sharing collaborators.
	 *
	 * The gateway, access policy, token-share service and federated-share
	 * service are real objects rather than mocks: every assertion in this
	 * class is about behaviour they inherited verbatim from CaseSharingService,
	 * and they stay driven entirely by the mocked app manager, container and
	 * settings passed in here.
	 *
	 * @param SettingsService $settings Settings service (mock).
	 * @param IAppManager $appManager App manager (mock).
	 * @param ContainerInterface $container DI container (mock).
	 * @param LoggerInterface $logger Logger (mock).
	 * @param TenantAuditTrailService $audit Audit trail (mock).
	 *
	 * @return CaseSharingService
	 */
	private static function makeSharingService(
		SettingsService $settings,
		IAppManager $appManager,
		ContainerInterface $container,
		LoggerInterface $logger,
		TenantAuditTrailService $audit,
	): CaseSharingService {
		$gateway = new OpenRegisterSharingGateway($appManager, $container, $logger);

		return new CaseSharingService(
			settingsService: $settings,
			gateway: $gateway,
			accessPolicy: new CaseAccessPolicy($settings, $gateway, $logger),
			tokenShares: new CaseTokenShareService($settings, $gateway, $logger),
			federatedShares: new FederatedCaseShareService($settings, $gateway, $logger, $audit),
			logger: $logger,
		);
	}//end makeSharingService()

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new CsfFakeObjectService();
		$this->federation = new CsfFakeFederationShareService();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) {
				return match ($key) {
					'register' => '1',
					'case_schema' => '2',
					'case_federated_share_schema' => '3',
					default => '',
				};
			}
		);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $this->objects,
					'OCA\OpenRegister\Service\FederationShareService' => $this->federation,
					default => throw new \RuntimeException('unexpected container lookup: ' . $id),
				};
			}
		);

		$this->audit = $this->createMock(TenantAuditTrailService::class);

		$this->service = self::makeSharingService(
			$settings,
			$appManager,
			$this->container,
			$this->createMock(LoggerInterface::class),
			$this->audit,
		);

		$this->objects->objects['case-1'] = [
			'id' => 'case-1',
			'title' => 'Illegal dumping near the border',
			'description' => 'Joint enforcement case',
			'status' => 'in_behandeling',
			'internalRiskScore' => 87,
			'documents' => ['doc-1', 'doc-2'],
			'@self' => ['organisation' => 'org-a', 'relations' => ['doc-1' => 'document']],
		];
	}//end setUp()

	/**
	 * @return void
	 */
	public function testCreateFederatedShareIncludesOnlyAllowListedFields(): void {
		$result = $this->service->createFederatedShare(
			'case-1',
			'partner@remote.example',
			['title', 'status'],
			[],
			'bekijken',
			'alice',
		);

		self::assertArrayNotHasKey('error', $result);
		self::assertSame(
			['title' => 'Illegal dumping near the border', 'status' => 'in_behandeling'],
			$result['fieldSnapshot']
		);
		self::assertArrayNotHasKey('internalRiskScore', $result['fieldSnapshot']);
	}//end testCreateFederatedShareIncludesOnlyAllowListedFields()

	/**
	 * @return void
	 */
	public function testCreateFederatedShareRejectsDisallowedField(): void {
		$result = $this->service->createFederatedShare(
			'case-1',
			'partner@remote.example',
			['internalRiskScore'],
			[],
			'bekijken',
			'alice',
		);

		self::assertArrayHasKey('error', $result);
		self::assertStringContainsString('internalRiskScore', $result['error']);
		self::assertCount(0, $this->federation->created, 'No OR share should be minted on a rejected request');
	}//end testCreateFederatedShareRejectsDisallowedField()

	/**
	 * @return void
	 */
	public function testSelfAndRelationsCanNeverCrossTheBoundary(): void {
		$result = $this->service->createFederatedShare(
			'case-1',
			'partner@remote.example',
			['title', '@self', 'relations'],
			[],
			'bekijken',
			'alice',
		);

		self::assertArrayHasKey('error', $result);
		self::assertCount(0, $this->federation->created);
	}//end testSelfAndRelationsCanNeverCrossTheBoundary()

	/**
	 * @return void
	 */
	public function testCreateFederatedShareRejectsDocumentNotAttachedToCase(): void {
		$result = $this->service->createFederatedShare(
			'case-1',
			'partner@remote.example',
			['title'],
			['doc-1', 'doc-999'],
			'bekijken',
			'alice',
		);

		self::assertArrayHasKey('error', $result);
		self::assertStringContainsString('doc-999', $result['error']);
	}//end testCreateFederatedShareRejectsDocumentNotAttachedToCase()

	/**
	 * @return void
	 */
	public function testCreateFederatedShareMintsReadOnlyOrShare(): void {
		$this->service->createFederatedShare(
			'case-1',
			'partner@remote.example',
			['title'],
			['doc-1'],
			'bekijken',
			'alice',
		);

		self::assertCount(1, $this->federation->created);
		$params = array_values($this->federation->created)[0];
		self::assertSame('read', $params['permissions']);
		self::assertSame('object', $params['scope']);
		self::assertSame('partner@remote.example', $params['sharedWith']);
	}//end testCreateFederatedShareMintsReadOnlyOrShare()

	/**
	 * @return void
	 */
	public function testCreateFederatedShareEmitsAuditEntry(): void {
		$this->audit->expects(self::once())
			->method('emit')
			->with(self::callback(static function (array $payload) {
				return $payload['action'] === 'federated_case_share_created'
					&& $payload['actor'] === 'alice'
					&& $payload['resource'] === 'case-1';
			}));

		$this->service->createFederatedShare('case-1', 'partner@remote.example', ['title'], [], 'bekijken', 'alice');
	}//end testCreateFederatedShareEmitsAuditEntry()

	/**
	 * @return void
	 */
	public function testCreateFederatedShareFailsClosedWhenOrFederationLeafUnavailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('1');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('federation leaf not installed'));

		$service = self::makeSharingService(
			$settings,
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TenantAuditTrailService::class),
		);

		$result = $service->createFederatedShare('case-1', 'partner@remote.example', ['title'], [], 'bekijken', 'alice');

		self::assertArrayHasKey('error', $result);
		self::assertStringContainsString('federation leaf', $result['error']);
	}//end testCreateFederatedShareFailsClosedWhenOrFederationLeafUnavailable()

	/**
	 * @return void
	 */
	public function testRevokeFederatedShareSetsOrStatusRevoked(): void {
		$created = $this->service->createFederatedShare('case-1', 'partner@remote.example', ['title'], [], 'bekijken', 'alice');
		$shareId = (string)$created['id'];

		$result = $this->service->revokeFederatedShare($shareId, 'bob');

		self::assertArrayNotHasKey('error', $result);
		self::assertSame('revoked', $result['status']);
		$federationShareId = $created['federationShareId'];
		self::assertSame('revoked', $this->federation->statusChanges[$federationShareId]);
	}//end testRevokeFederatedShareSetsOrStatusRevoked()

	/**
	 * @return void
	 */
	public function testRevokeFederatedShareFailsClosedWhenOrUnavailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		$service = self::makeSharingService(
			$this->createMock(SettingsService::class),
			$appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TenantAuditTrailService::class),
		);

		$result = $service->revokeFederatedShare('anything', 'bob');
		self::assertArrayHasKey('error', $result);
	}//end testRevokeFederatedShareFailsClosedWhenOrUnavailable()

	/**
	 * @return void
	 */
	public function testGetCaseIdForFederatedShareResolvesCreatedShare(): void {
		$created = $this->service->createFederatedShare('case-1', 'partner@remote.example', ['title'], [], 'bekijken', 'alice');

		$caseId = $this->service->getCaseIdForFederatedShare((string)$created['id']);
		self::assertSame('case-1', $caseId);
	}//end testGetCaseIdForFederatedShareResolvesCreatedShare()

	/**
	 * @return void
	 */
	public function testGetCaseIdForFederatedShareReturnsNullForUnknownShare(): void {
		self::assertNull($this->service->getCaseIdForFederatedShare('does-not-exist'));
	}//end testGetCaseIdForFederatedShareReturnsNullForUnknownShare()
}//end class
