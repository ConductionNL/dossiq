<?php

/**
 * Case access link unit tests.
 *
 * Drives CaseSharingService over a REAL CaseAccessLinkService and a REAL
 * OpenRegisterSharingGateway, with hand-written fakes standing in for
 * OpenRegister's own AccessLinkService, AccessLinkReader and ObjectService.
 * The fakes carry real named parameters, because the production calls use
 * them and a PHPUnit double would accept an argument order the real class
 * would not.
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
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\AccessLinkProjection;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake of OpenRegister's AccessLinkService.
 *
 * `revoke()` refuses a caller that did not mint the link, exactly as the real
 * one does through `ownedLink()`. That refusal is the whole point of two of
 * the tests below.
 */
final class CalFakeAccessLinkService {
	/** @var array<int, array<string, mixed>> */
	public array $minted = [];

	/** @var array<int, int> */
	public array $revoked = [];

	/** @var array<int, bool> */
	public array $disabled = [];

	public bool $throwOnMint = false;

	private int $autoId = 10;

	/**
	 * @param array<int, string> $capabilities
	 *
	 * @return array<string, mixed>
	 */
	public function mint(
		string $userId,
		string $subjectType,
		string $subjectId,
		array $capabilities = [],
		?string $expiresAt = null,
		?string $password = null,
		?string $label = null,
	): array {
		if ($this->throwOnMint === true) {
			throw new \RuntimeException('no link for you');
		}

		$id = $this->autoId++;
		$this->minted[$id] = [
			'id' => $id,
			'uuid' => 'uuid-' . $id,
			'anchor' => 'anchor' . $id,
			'subjectType' => $subjectType,
			'subjectId' => $subjectId,
			'capabilities' => $capabilities,
			'label' => $label,
			'hasPassword' => ($password !== null),
			'createdBy' => $userId,
			'expiresAt' => $expiresAt,
			'revokedAt' => null,
			'disabled' => false,
			'url' => 'https://example.test/index.php/apps/openregister/link/anchor' . $id,
		];

		return $this->minted[$id];
	}//end mint()

	public function revoke(int $id, string $userId): bool {
		$link = ($this->minted[$id] ?? null);
		if ($link === null || $link['createdBy'] !== $userId) {
			return false;
		}

		$this->revoked[] = $id;
		return true;
	}//end revoke()

	/**
	 * @return array<string, mixed>|null
	 */
	public function setDisabled(int $id, string $userId, bool $disabled): ?array {
		$link = ($this->minted[$id] ?? null);
		if ($link === null || $link['createdBy'] !== $userId) {
			return null;
		}

		$this->disabled[$id] = $disabled;
		$this->minted[$id]['disabled'] = $disabled;
		return $this->minted[$id];
	}//end setDisabled()

	/**
	 * @return array<string, mixed>|null
	 */
	public function resolve(string $anchor): ?array {
		foreach ($this->minted as $link) {
			if ($link['anchor'] === $anchor) {
				return $link;
			}
		}

		return null;
	}//end resolve()
}//end class

/**
 * Fake of OpenRegister's AccessLinkReader.
 *
 * It deliberately returns a body whose `@self` still carries internals, so
 * the dossiq-side strip is the thing under test rather than OpenRegister's.
 */
final class CalFakeAccessLinkReader {
	/** @var array<string, mixed>|null */
	public ?array $body = null;

	/**
	 * @param mixed $link
	 *
	 * @return array<string, mixed>|null
	 */
	public function read($link, $object = null): ?array {
		unset($link, $object);
		return $this->body;
	}//end read()
}//end class

/**
 * Fake of OpenRegister's ObjectService, over an in-memory store.
 */
final class CalFakeObjectService {
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
		unset($args);
		return ($this->objects[(string)$id] ?? null);
	}//end find()

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, int $register, int $schema, ?string $uuid = null): array {
		unset($register, $schema);
		$id = ($object['id'] ?? $uuid ?? ('share-' . $this->autoId++));
		$object['id'] = $id;
		$this->objects[(string)$id] = $object;
		return $object;
	}//end saveObject()

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$filters = ($config['filters'] ?? []);
		$rows = [];
		foreach ($this->objects as $object) {
			if (isset($filters['caseId']) === true && ($object['caseId'] ?? null) !== $filters['caseId']) {
				continue;
			}

			if (isset($filters['shareType']) === true && ($object['shareType'] ?? null) !== $filters['shareType']) {
				continue;
			}

			$rows[] = $object;
		}

		return $rows;
	}//end findAll()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Sharing\CaseAccessLinkService
 *
 * @uses \OCA\Dossiq\Service\CaseSharingService
 * @uses \OCA\Dossiq\Service\Sharing\AccessLinkProjection
 * @uses \OCA\Dossiq\Service\Sharing\CaseAccessPolicy
 * @uses \OCA\Dossiq\Service\Sharing\CaseLinkShares
 * @uses \OCA\Dossiq\Service\Sharing\FederatedCaseShareService
 * @uses \OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway
 */
class CaseAccessLinkServiceTest extends TestCase {
	private CalFakeAccessLinkService $links;

	private CalFakeAccessLinkReader $reader;

	private CalFakeObjectService $objects;

	private CaseSharingService $service;

	private CaseAccessLinkService $accessLinks;

	private CaseLinkShares $linkShares;

	/**
	 * Assemble the sharing service over the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->links = new CalFakeAccessLinkService();
		$this->reader = new CalFakeAccessLinkReader();
		$this->objects = new CalFakeObjectService();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				return match ($id) {
					'OCA\OpenRegister\Service\Sharing\AccessLinkService' => $this->links,
					'OCA\OpenRegister\Service\Sharing\AccessLinkReader' => $this->reader,
					'OCA\OpenRegister\Service\ObjectService' => $this->objects,
					default => throw new \RuntimeException('no ' . $id),
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('1');

		$gateway = new OpenRegisterSharingGateway($appManager, $container, $logger);
		$this->accessLinks = new CaseAccessLinkService($gateway, new AccessLinkProjection(), $logger);
		$this->linkShares = new CaseLinkShares($settings, $gateway, $this->accessLinks, $logger);

		$this->service = new CaseSharingService(
			settingsService: $settings,
			gateway: $gateway,
			accessPolicy: new CaseAccessPolicy($settings, $gateway, $logger),
			accessLinks: $this->accessLinks,
			linkShares: $this->linkShares,
			federatedShares: new FederatedCaseShareService(
				$settings,
				$gateway,
				$logger,
				$this->createMock(TenantAuditTrailService::class)
			),
			consent: $this->allowingConsentGate(),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * A consent gate that lets every partner share through.
	 *
	 * Stubbed here because this test's subject is the federated share and the
	 * access link, not the consent. REQ-CST-01 and REQ-CST-02 are watched
	 * against a real in-memory register in PartnerShareScopeTest.
	 *
	 * @return CaseTransferConsentGate The gate.
	 */
	private function allowingConsentGate(): CaseTransferConsentGate {
		$gate = $this->createStub(CaseTransferConsentGate::class);
		$gate->method('assess')->willReturn(
			[
				'allowed' => true,
				'rule' => '',
				'sentence' => '',
				'consent' => null,
				'scope' => [],
				'until' => '',
				'crossesOrganisation' => true,
			]
		);

		return $gate;
	}//end allowingConsentGate()

	/**
	 * A share mints a link over the case, declaring what the holder may do,
	 * and records the link on the share.
	 *
	 * @return void
	 */
	public function testAShareMintsALinkOverTheCase(): void {
		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
			capabilities: ['comment'],
		);

		self::assertArrayNotHasKey('error', $result);
		self::assertNotSame('', $result['url'], 'A share without an address is a share nobody can use.');

		$minted = $this->links->minted[array_key_first($this->links->minted)];
		self::assertSame('object', $minted['subjectType']);
		self::assertSame('case-1', $minted['subjectId']);
		self::assertSame(['read', 'comment'], $minted['capabilities'], 'Reading is always granted beside what was asked for.');

		$share = $result['share'];
		self::assertSame('link', $share['shareType']);
		self::assertSame('read,comment', $share['capabilities']);
		self::assertSame($minted['id'], $share['accessLinkId']);
		self::assertSame($minted['uuid'], $share['accessLinkUuid']);
		self::assertSame('active', $share['status']);
		self::assertArrayNotHasKey('token', $share, 'dossiq mints no token of its own.');
	}//end testAShareMintsALinkOverTheCase()

	/**
	 * A capability that is not one refuses the whole share, and nothing is
	 * minted.
	 *
	 * @return void
	 */
	public function testAnUnknownCapabilityRefusesTheShare(): void {
		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: '',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
			capabilities: ['delete'],
		);

		self::assertArrayHasKey('error', $result);
		self::assertSame([], $this->links->minted, 'A refused capability must not leave a link behind.');
	}//end testAnUnknownCapabilityRefusesTheShare()

	/**
	 * Each document named on the share gets its own file link, addressed as
	 * the object uuid and the file id.
	 *
	 * @return void
	 */
	public function testSharedDocumentsMintFileLinks(): void {
		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
			capabilities: ['comment'],
			password: null,
			sharedDocuments: ['77'],
		);

		$subjects = array_map(
			static fn (array $link): string => $link['subjectType'] . ':' . $link['subjectId'],
			array_values($this->links->minted)
		);

		self::assertSame(['object:case-1', 'file:case-1/77'], $subjects);

		$documents = json_decode((string)$result['share']['sharedDocuments'], true);
		self::assertCount(1, $documents);
		self::assertSame('77', $documents[0]['fileId']);
		self::assertNotNull($documents[0]['url'], 'A shared document without an address cannot be sent.');
	}//end testSharedDocumentsMintFileLinks()

	/**
	 * Revoking the share revokes the link OpenRegister minted for it.
	 *
	 * @return void
	 */
	public function testRevokeShareRevokesTheLink(): void {
		$created = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
		);

		$revoked = $this->service->revokeShare((string)$created['share']['id'], 'anja');

		self::assertSame([$created['share']['accessLinkId']], $this->links->revoked);
		self::assertSame('revoked', $revoked['status']);
		self::assertArrayNotHasKey('linksNotRevoked', $revoked);
	}//end testRevokeShareRevokesTheLink()

	/**
	 * Revoking takes every file link with it, not only the case link.
	 *
	 * @return void
	 */
	public function testRevokeTakesFileLinksWithIt(): void {
		$created = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
			capabilities: ['comment'],
			password: null,
			sharedDocuments: ['77', '78'],
		);

		$this->service->revokeShare((string)$created['share']['id'], 'anja');

		self::assertCount(3, $this->links->revoked, 'The case link and both file links must be revoked.');
		self::assertSame(array_keys($this->links->minted), $this->links->revoked);
	}//end testRevokeTakesFileLinksWithIt()

	/**
	 * OpenRegister revokes a link only for the colleague who minted it, and
	 * dossiq reports that refusal rather than reading it as a revoke.
	 *
	 * @return void
	 */
	public function testRevokeByAnotherUserIsReported(): void {
		$created = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
		);

		$revoked = $this->service->revokeShare((string)$created['share']['id'], 'bram');

		self::assertSame([], $this->links->revoked, 'A colleague cannot revoke a link they did not mint.');
		self::assertSame(
			[$created['share']['accessLinkId']],
			$revoked['linksNotRevoked'],
			'A refusal that is not carried back reads as a revoke that happened.'
		);
	}//end testRevokeByAnotherUserIsReported()

	/**
	 * A link on another case cannot be reached by naming its id.
	 *
	 * @return void
	 */
	public function testALinkOnAnotherCaseDoesNotBelongToThisOne(): void {
		$created = $this->service->createTokenShare(
			caseId: 'case-2',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
		);

		$linkId = (int)$created['share']['accessLinkId'];

		self::assertTrue($this->linkShares->belongsToCase($linkId, 'case-2'));
		self::assertFalse($this->linkShares->belongsToCase($linkId, 'case-1'));
	}//end testALinkOnAnotherCaseDoesNotBelongToThisOne()

	/**
	 * The four states are read off the link row, and revoked wins over
	 * expired.
	 *
	 * @return void
	 */
	public function testStateOfReadsTheLinkRow(): void {
		self::assertSame('live', $this->accessLinks->stateOf(['expiresAt' => '2099-01-01']));
		self::assertSame('expired', $this->accessLinks->stateOf(['expiresAt' => '2000-01-01']));
		self::assertSame('paused', $this->accessLinks->stateOf(['disabled' => true, 'expiresAt' => '2099-01-01']));
		self::assertSame(
			'revoked',
			$this->accessLinks->stateOf(['revokedAt' => '2026-01-01', 'expiresAt' => '2000-01-01']),
			'A revoked link that also expired is revoked: that is the fact the handler acted on.'
		);
	}//end testStateOfReadsTheLinkRow()

	/**
	 * The preview drops the `@self` internals OpenRegister does not publish,
	 * and keeps the ones it does.
	 *
	 * @return void
	 */
	public function testPreviewDropsSelfInternals(): void {
		$created = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
		);

		$this->reader->body = [
			'subject' => [
				'title' => 'Vergunning',
				'@self' => [
					'uuid' => 'case-1',
					'name' => 'Vergunning',
					'published' => '2026-01-01',
					'owner' => 'anja',
					'organisation' => 'gemeente',
					'folder' => '/Cases/1',
					'groups' => ['handlers'],
				],
				'@relations' => ['secret-uuid'],
			],
		];

		$preview = $this->linkShares->holderPreview((int)$created['share']['accessLinkId'], 'case-1');

		self::assertNotNull($preview);
		self::assertSame(
			['uuid', 'name', 'published'],
			array_keys($preview['subject']['@self']),
			'Only the keys OpenRegister publishes may reach a holder.'
		);
		self::assertArrayNotHasKey('@relations', $preview['subject']);
		self::assertSame('Vergunning', $preview['subject']['title'], 'The case fields themselves stay.');
	}//end testPreviewDropsSelfInternals()

	/**
	 * A preview of a link the case did not mint answers nothing.
	 *
	 * @return void
	 */
	public function testPreviewOfALinkOnAnotherCaseAnswersNothing(): void {
		$created = $this->service->createTokenShare(
			caseId: 'case-2',
			label: 'Brandweer',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
		);

		$this->reader->body = ['subject' => ['title' => 'Vergunning']];

		self::assertNull($this->linkShares->holderPreview((int)$created['share']['accessLinkId'], 'case-1'));
	}//end testPreviewOfALinkOnAnotherCaseAnswersNothing()

	/**
	 * An OpenRegister that cannot mint is reported as an error, never as a
	 * share that happened.
	 *
	 * @return void
	 */
	public function testAFailedMintIsNotAShare(): void {
		$this->links->throwOnMint = true;

		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: '',
			createdBy: 'anja',
			expiresAt: '2030-01-01',
		);

		self::assertArrayHasKey('error', $result);
		self::assertSame([], $this->objects->objects, 'No link means no share record.');
	}//end testAFailedMintIsNotAShare()
}//end class
