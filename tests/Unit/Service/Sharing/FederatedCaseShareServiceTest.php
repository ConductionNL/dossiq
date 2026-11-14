<?php

/**
 * Federated Case Share Service Test
 *
 * Pins how a case is shared with, and withdrawn from, a partner instance:
 * which fields and documents may cross the boundary, the record written
 * before and after the federation token is minted, the audit event each
 * step emits, and every refusal on the way.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FederatedCaseShareService.
 */
#[CoversClass(FederatedCaseShareService::class)]
#[UsesClass(OpenRegisterSharingGateway::class)]
final class FederatedCaseShareServiceTest extends TestCase {
	private const CONFIG = [
		'register' => '5',
		'case_schema' => '3',
		'case_federated_share_schema' => '11',
	];

	/**
	 * Calls made on the OpenRegister stand-ins, in order.
	 *
	 * @var \ArrayObject<int,array<int,mixed>>
	 */
	private \ArrayObject $calls;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The audit trail.
	 *
	 * @var TenantAuditTrailService&MockObject
	 */
	private TenantAuditTrailService $audit;

	/**
	 * Set up the call log, logger and audit trail.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calls = new \ArrayObject();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->audit = $this->createMock(TenantAuditTrailService::class);
	}

	/**
	 * The service over OpenRegister stand-ins.
	 *
	 * @param object|null          $objects    The ObjectService stand-in.
	 * @param object|null          $federation The FederationShareService stand-in.
	 * @param array<string,string> $config     The dossiq config values.
	 *
	 * @return FederatedCaseShareService
	 */
	private function service(?object $objects, ?object $federation, array $config = self::CONFIG): FederatedCaseShareService {
		$services = array_filter(
			[
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCA\OpenRegister\Service\FederationShareService' => $federation,
			]
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $services[$id];
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return new FederatedCaseShareService(
			$settings,
			new OpenRegisterSharingGateway($appManager, $container, $this->logger),
			$this->logger,
			$this->audit
		);
	}

	/**
	 * An ObjectService stand-in.
	 *
	 * @param array<string,mixed> $found     What find answers, keyed by id; a Throwable is thrown.
	 * @param bool                $serialise Whether saveObject answers a JsonSerializable instead of an array.
	 *
	 * @return object
	 */
	private function objects(array $found, bool $serialise = false): object {
		return new class($found, $serialise, $this->calls) {
			private int $saves = 0;

			public function __construct(private array $found, private bool $serialise, private \ArrayObject $calls) {
			}

			public function find(string $id, int $register, int $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				$found = ($this->found[$id] ?? null);
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}

			public function saveObject(array $object, int $register, int $schema): mixed {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				$this->saves++;
				$saved = $object + ['id' => 'fshare-1', 'save' => $this->saves];
				if ($this->serialise === false) {
					return $saved;
				}

				return new class($saved) implements \JsonSerializable {
					public function __construct(private array $saved) {
					}

					public function jsonSerialize(): array {
						return $this->saved;
					}
				};
			}
		};
	}

	/**
	 * A FederationShareService stand-in.
	 *
	 * @param \Throwable|null $fail Thrown by both methods when set.
	 *
	 * @return object
	 */
	private function federation(?\Throwable $fail = null): object {
		return new class($fail, $this->calls) {
			public function __construct(private ?\Throwable $fail, private \ArrayObject $calls) {
			}

			public function createOutgoingShare(array $params): object {
				$this->calls[] = ['createOutgoingShare', $params];
				if ($this->fail !== null) {
					throw $this->fail;
				}

				return new class {
					public function getId(): int {
						return 77;
					}
				};
			}

			public function setStatus(int $id, string $status): void {
				$this->calls[] = ['setStatus', $id, $status];
				if ($this->fail !== null) {
					throw $this->fail;
				}
			}
		};
	}

	/**
	 * The case every create test shares.
	 *
	 * @return array<string,mixed>
	 */
	private function caseRow(): array {
		return ['id' => 'case-1', 'title' => 'Bouwvergunning', 'status' => 'open', 'bsn' => '123', 'documents' => ['doc-1', 'doc-2']];
	}

	/**
	 * The calls of one kind, in order.
	 *
	 * @param string $kind The method name.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function callsOf(string $kind): array {
		return array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] === $kind));
	}

	/**
	 * A share records a pending snapshot of the allowed fields, mints the token, then activates the record.
	 *
	 * @return void
	 */
	public function testCreateRecordsMintsAndActivates(): void {
		$this->audit->expects($this->once())->method('emit')->with(
			['action' => 'federated_case_share_created', 'actor' => 'ann', 'resource' => 'case-1', 'tenantId' => 'bob@partner.example']
		);

		$result = $this->service(objects: $this->objects(found: ['case-1' => $this->caseRow()]), federation: $this->federation())->createFederatedShare(
			caseId: 'case-1',
			remoteCloudId: 'bob@partner.example',
			sharedFields: ['title', 'status', 'dueDate'],
			sharedDocuments: ['doc-2'],
			permissionLevel: 'bekijken',
			createdBy: 'ann'
		);

		$pending = [
			'caseId' => 'case-1',
			'remoteCloudId' => 'bob@partner.example',
			'sharedFields' => ['title', 'status', 'dueDate'],
			'sharedDocuments' => ['doc-2'],
			'fieldSnapshot' => ['title' => 'Bouwvergunning', 'status' => 'open'],
			'permissionLevel' => 'bekijken',
			'status' => 'pending',
			'createdBy' => 'ann',
		];
		$saves = $this->callsOf(kind: 'saveObject');
		$this->assertSame(['saveObject', $pending, 5, 11], $saves[0]);
		$this->assertSame(
			[['createOutgoingShare', ['scope' => 'object', 'register' => '5', 'schema' => '11', 'objectUri' => 'fshare-1', 'sharedWith' => 'bob@partner.example', 'permissions' => 'read']]],
			$this->callsOf(kind: 'createOutgoingShare')
		);
		$this->assertSame(['status' => 'active', 'federationShareId' => 77], array_intersect_key($saves[1][1], ['status' => 1, 'federationShareId' => 1]));
		$this->assertSame('active', $result['status']);
		$this->assertSame(77, $result['federationShareId']);
		$this->assertCount(2, $saves);
		$this->assertSame(['find', 'case-1', 5, 3], $this->calls[0]);
	}

	/**
	 * Without the federation leaf, with a field outside the allow-list, or unconfigured, nothing is read or written.
	 *
	 * @return void
	 */
	public function testCreateRefusesBeforeReading(): void {
		$objects = $this->objects(found: ['case-1' => $this->caseRow()]);
		$create = static fn (FederatedCaseShareService $service, array $fields): array => $service->createFederatedShare(
			caseId: 'case-1',
			remoteCloudId: 'bob@partner.example',
			sharedFields: $fields,
			sharedDocuments: [],
			permissionLevel: 'bekijken',
			createdBy: 'ann'
		);

		$this->assertSame(
			['error' => 'Federated case sharing requires the OpenRegister federation leaf'],
			$create($this->service(objects: $objects, federation: null), ['title'])
		);
		$this->assertSame(
			['error' => 'Field(s) not shareable across a federation boundary: bsn, notes'],
			$create($this->service(objects: $objects, federation: $this->federation()), ['title', 'bsn', 'notes'])
		);
		$this->assertSame(
			['error' => 'Federated case sharing is not configured'],
			$create($this->service(objects: $objects, federation: $this->federation(), config: ['register' => '5', 'case_schema' => '3']), ['title'])
		);
		$this->assertSame([], $this->calls->getArrayCopy());
	}

	/**
	 * A missing or unreadable case, or a document not on the case, refuses without writing.
	 *
	 * @return void
	 */
	public function testCreateRefusesAnUnknownCaseOrForeignDocument(): void {
		$service = $this->service(
			objects: $this->objects(found: ['case-1' => $this->caseRow(), 'case-broken' => new RuntimeException('store down')]),
			federation: $this->federation()
		);
		$create = static fn (string $caseId, array $documents): array => $service->createFederatedShare(
			caseId: $caseId,
			remoteCloudId: 'bob@partner.example',
			sharedFields: ['title'],
			sharedDocuments: $documents,
			permissionLevel: 'bekijken',
			createdBy: 'ann'
		);

		$this->logger->expects($this->once())->method('warning')->with(
			'CaseSharingService: createFederatedShare case load failed',
			['caseId' => 'case-broken', 'exception' => 'store down']
		);

		$this->assertSame(['error' => 'Case not found'], $create('case-missing', []));
		$this->assertSame(['error' => 'Case not found'], $create('case-broken', []));
		$this->assertSame(['error' => 'Document(s) not attached to this case: doc-9'], $create('case-1', ['doc-1', 'doc-9']));
		$this->assertSame([], $this->callsOf(kind: 'saveObject'));
	}

	/**
	 * When the token cannot be minted the share stays pending and the failure is named, without an audit event.
	 *
	 * @return void
	 */
	public function testCreateNamesAFailedMint(): void {
		$this->audit->expects($this->never())->method('emit');
		$this->logger->expects($this->once())->method('error')->with(
			'CaseSharingService: OR createOutgoingShare failed',
			['caseId' => 'case-1', 'exception' => 'partner down']
		);

		$result = $this->service(
			objects: $this->objects(found: ['case-1' => $this->caseRow()]),
			federation: $this->federation(fail: new RuntimeException('partner down'))
		)->createFederatedShare(
			caseId: 'case-1',
			remoteCloudId: 'bob@partner.example',
			sharedFields: ['title'],
			sharedDocuments: [],
			permissionLevel: 'bekijken',
			createdBy: 'ann'
		);

		$this->assertSame(['error' => 'Could not mint the federated share token'], $result);
		$this->assertCount(1, $this->callsOf(kind: 'saveObject'));
	}

	/**
	 * Revoking withdraws the federation token, then marks the record revoked by the user and audits it.
	 *
	 * @return void
	 */
	public function testRevokeWithdrawsTheTokenAndMarksTheRecord(): void {
		$this->audit->expects($this->once())->method('emit')->with(
			['action' => 'federated_case_share_revoked', 'actor' => 'ann', 'resource' => 'case-1', 'tenantId' => 'bob@partner.example']
		);

		$result = $this->service(
			objects: $this->objects(
				found: ['fshare-1' => ['id' => 'fshare-1', 'caseId' => 'case-1', 'remoteCloudId' => 'bob@partner.example', 'federationShareId' => '77', 'status' => 'active']],
				serialise: true
			),
			federation: $this->federation()
		)->revokeFederatedShare(shareId: 'fshare-1', userId: 'ann');

		$this->assertSame([['setStatus', 77, 'revoked']], $this->callsOf(kind: 'setStatus'));
		$this->assertSame('revoked', $result['status']);
		$this->assertSame('ann', $result['revokedBy']);
		$this->assertNotFalse(\DateTime::createFromFormat(\DateTimeInterface::ATOM, $result['revokedAt']));
		$this->assertSame(['find', 'fshare-1', 5, 11], $this->calls[0]);
	}

	/**
	 * A share without a federation token is still marked revoked, and the saved array is returned as is.
	 *
	 * @return void
	 */
	public function testRevokeWithoutATokenOnlyMarksTheRecord(): void {
		$result = $this->service(
			objects: $this->objects(found: ['fshare-1' => ['id' => 'fshare-1']]),
			federation: $this->federation()
		)->revokeFederatedShare(shareId: 'fshare-1', userId: 'ann');

		$this->assertSame([], $this->callsOf(kind: 'setStatus'));
		$this->assertSame('revoked', $result['status']);
		$this->assertSame('fshare-1', $result['id']);
	}

	/**
	 * Revoking refuses without the federation leaf, unconfigured, for an unknown share, and when the token withdrawal fails.
	 *
	 * @return void
	 */
	public function testRevokeRefusals(): void {
		$objects = $this->objects(found: ['fshare-1' => ['id' => 'fshare-1', 'federationShareId' => 77]]);

		$this->assertSame(
			['error' => 'Federated case sharing requires the OpenRegister federation leaf'],
			$this->service(objects: $objects, federation: null)->revokeFederatedShare(shareId: 'fshare-1', userId: 'ann')
		);
		$this->assertSame(
			['error' => 'Federated case sharing is not configured'],
			$this->service(objects: $objects, federation: $this->federation(), config: ['register' => '5'])->revokeFederatedShare(shareId: 'fshare-1', userId: 'ann')
		);
		$this->assertSame(
			['error' => 'Federated share not found'],
			$this->service(objects: $objects, federation: $this->federation())->revokeFederatedShare(shareId: 'fshare-9', userId: 'ann')
		);

		$this->logger->expects($this->once())->method('error')->with(
			'CaseSharingService: OR federated-share revoke failed',
			['shareId' => 'fshare-1', 'exception' => 'partner down']
		);
		$this->assertSame(
			['error' => 'Could not revoke the federated share token'],
			$this->service(objects: $objects, federation: $this->federation(fail: new RuntimeException('partner down')))->revokeFederatedShare(shareId: 'fshare-1', userId: 'ann')
		);
		$this->assertSame([], $this->callsOf(kind: 'saveObject'));
	}

	/**
	 * The case of a federated share is read from its record, array or serialisable.
	 *
	 * @return void
	 */
	public function testGetCaseIdReadsTheShareRecord(): void {
		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['caseId' => 'case-2'];
			}
		};
		$service = $this->service(
			objects: $this->objects(
				found: [
					'fshare-1' => ['caseId' => 'case-1'],
					'fshare-2' => $serialisable,
					'fshare-3' => ['status' => 'active'],
					'fshare-4' => new RuntimeException('store down'),
				]
			),
			federation: null
		);

		$this->assertSame('case-1', $service->getCaseIdForFederatedShare(shareId: 'fshare-1'));
		$this->assertSame('case-2', $service->getCaseIdForFederatedShare(shareId: 'fshare-2'));
		$this->assertNull($service->getCaseIdForFederatedShare(shareId: 'fshare-3'));
		$this->assertNull($service->getCaseIdForFederatedShare(shareId: 'fshare-9'));

		$this->logger->expects($this->once())->method('debug')->with(
			'CaseSharingService: getCaseIdForFederatedShare failed',
			['shareId' => 'fshare-4', 'exception' => 'store down']
		);
		$this->assertNull($service->getCaseIdForFederatedShare(shareId: 'fshare-4'));
	}

	/**
	 * Without OpenRegister's ObjectService or a configured share schema there is no case to name.
	 *
	 * @return void
	 */
	public function testGetCaseIdWithoutStoreOrConfigIsNull(): void {
		$this->assertNull($this->service(objects: null, federation: null)->getCaseIdForFederatedShare(shareId: 'fshare-1'));
		$this->assertNull(
			$this->service(objects: $this->objects(found: ['fshare-1' => ['caseId' => 'case-1']]), federation: null, config: ['register' => '5'])
				->getCaseIdForFederatedShare(shareId: 'fshare-1')
		);
		$this->assertSame([], $this->calls->getArrayCopy());
	}
}
