<?php

/**
 * Case Link Shares Test
 *
 * Pins how a case's link shares are read, recorded, collected and withdrawn:
 * the filter a listing sends, the state each share is given, the record a
 * mint writes, the refusals when sharing is not configured or the write
 * fails, and which link ids a withdrawal revokes.
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
use OCA\Dossiq\Service\Sharing\AccessLinkProjection;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CaseLinkShares.
 */
#[CoversClass(CaseLinkShares::class)]
#[UsesClass(OpenRegisterSharingGateway::class)]
#[UsesClass(CaseAccessLinkService::class)]
#[UsesClass(AccessLinkProjection::class)]
final class CaseLinkSharesTest extends TestCase {
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
	 * Set up the call log and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calls = new \ArrayObject();
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The shares service over OpenRegister stand-ins.
	 *
	 * @param object|null          $objects   The ObjectService stand-in, or null when it does not resolve.
	 * @param array<string,string> $config    The dossiq config values.
	 * @param object|null          $links     The AccessLinkService stand-in.
	 * @param object|null          $reader    The AccessLinkReader stand-in.
	 * @param bool                 $installed Whether OpenRegister is installed.
	 *
	 * @return CaseLinkShares
	 */
	private function shares(
		?object $objects,
		array $config = ['register' => '5', 'case_share_schema' => '9'],
		?object $links = null,
		?object $reader = null,
		bool $installed = true,
	): CaseLinkShares {
		$services = array_filter(
			[
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCA\OpenRegister\Service\Sharing\AccessLinkService' => $links,
				'OCA\OpenRegister\Service\Sharing\AccessLinkReader' => $reader,
			]
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);
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

		$gateway = new OpenRegisterSharingGateway($appManager, $container, $this->logger);

		return new CaseLinkShares(
			$settings,
			$gateway,
			new CaseAccessLinkService($gateway, new AccessLinkProjection(), $this->logger),
			$this->logger
		);
	}

	/**
	 * An ObjectService stand-in.
	 *
	 * @param array<int,mixed>          $rows   What findAll answers.
	 * @param mixed                     $found  What find answers.
	 * @param \Throwable|null           $fail   Thrown by findAll, find and saveObject when set.
	 * @param array<string,mixed>|null  $saved  What saveObject answers; null echoes the object with an id.
	 *
	 * @return object
	 */
	private function objects(array $rows = [], mixed $found = null, ?\Throwable $fail = null, ?array $saved = null): object {
		return new class($rows, $found, $fail, $saved, $this->calls) {
			public function __construct(
				private array $rows,
				private mixed $found,
				private ?\Throwable $fail,
				private ?array $saved,
				private \ArrayObject $calls,
			) {
			}

			public function findAll(array $config): array {
				$this->calls[] = ['findAll', $config];
				if ($this->fail !== null) {
					throw $this->fail;
				}

				return $this->rows;
			}

			public function find(string $id, int $register, int $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];

				return $this->found;
			}

			public function saveObject(array $object, int $register, int $schema): array {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				if ($this->fail !== null) {
					throw $this->fail;
				}

				return ($this->saved ?? ($object + ['id' => 'share-1']));
			}
		};
	}

	/**
	 * An AccessLinkService stand-in that refuses to revoke the given ids.
	 *
	 * @param array<int,int>           $refuse The ids whose revoke is refused.
	 * @param array<string,mixed>|null $link   What resolve answers.
	 *
	 * @return object
	 */
	private function links(array $refuse = [], ?array $link = null): object {
		return new class($refuse, $link, $this->calls) {
			public function __construct(private array $refuse, private ?array $link, private \ArrayObject $calls) {
			}

			public function mint(): array {
				return [];
			}

			public function revoke(int $linkId, string $userId): bool {
				$this->calls[] = ['revoke', $linkId, $userId];

				return in_array($linkId, $this->refuse, true) === false;
			}

			public function setDisabled(int $linkId, string $userId, bool $paused): array {
				$this->calls[] = ['setDisabled', $linkId, $userId, $paused];

				return ['id' => $linkId, 'disabled' => $paused];
			}

			public function resolve(string $anchor): ?array {
				$this->calls[] = ['resolve', $anchor];

				return $this->link;
			}
		};
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
	 * A listing asks for the case's link shares and gives each its state; rows that are not records are dropped.
	 *
	 * @return void
	 */
	public function testListForCaseFiltersAndStatesEachShare(): void {
		$shares = $this->shares(
			objects: $this->objects(
				rows: [
					['accessLinkId' => 1],
					['accessLinkId' => 2, 'revokedAt' => '2026-01-01T00:00:00+00:00'],
					['accessLinkId' => 3, 'disabled' => true],
					['accessLinkId' => 4, 'expiresAt' => '2000-01-01T00:00:00+00:00'],
					new \stdClass(),
				]
			)
		)->listForCase(caseId: 'case-1');

		$this->assertSame(
			[
				['findAll', ['filters' => ['register' => 5, 'schema' => 9, 'caseId' => 'case-1', 'shareType' => 'link']]],
			],
			$this->calls->getArrayCopy()
		);
		$this->assertSame(['live', 'revoked', 'paused', 'expired'], array_column($shares, 'state'));
		$this->assertSame([1, 2, 3, 4], array_column($shares, 'accessLinkId'));
	}

	/**
	 * Without a configured share schema nothing is read; a failing read lists nothing and warns.
	 *
	 * @return void
	 */
	public function testListForCaseReadsNothingWhenUnconfiguredOrFailing(): void {
		$this->assertSame([], $this->shares(objects: $this->objects(rows: [['accessLinkId' => 1]]), config: ['register' => '5'])->listForCase(caseId: 'case-1'));
		$this->assertSame([], $this->calls->getArrayCopy());

		$this->logger->expects($this->once())->method('warning')->with(
			'CaseLinkShares: could not list the case access links',
			['caseId' => 'case-1', 'exception' => 'store down']
		);
		$this->assertSame([], $this->shares(objects: $this->objects(fail: new RuntimeException('store down')))->listForCase(caseId: 'case-1'));
	}

	/**
	 * A link belongs to a case only when one of its shares names it; a non-positive id never does.
	 *
	 * @return void
	 */
	public function testBelongsToCaseMatchesTheShareLinkId(): void {
		$shares = $this->shares(objects: $this->objects(rows: [['accessLinkId' => '7']]));

		$this->assertTrue($shares->belongsToCase(linkId: 7, caseId: 'case-1'));
		$this->assertFalse($shares->belongsToCase(linkId: 8, caseId: 'case-1'));
		$calls = count($this->calls);
		$this->assertFalse($shares->belongsToCase(linkId: 0, caseId: 'case-1'));
		$this->assertCount($calls, $this->calls);
	}

	/**
	 * Revoke and pause hand the link and user to OpenRegister.
	 *
	 * @return void
	 */
	public function testRevokeAndPauseDelegateToOpenRegister(): void {
		$shares = $this->shares(objects: $this->objects(), links: $this->links(refuse: [9]));

		$this->assertTrue($shares->revokeLink(linkId: 7, userId: 'ann'));
		$this->assertFalse($shares->revokeLink(linkId: 9, userId: 'ann'));
		$this->assertSame(['id' => 7, 'disabled' => true], $shares->pauseLink(linkId: 7, userId: 'ann', paused: true));
		$this->assertSame(
			[['revoke', 7, 'ann'], ['revoke', 9, 'ann'], ['setDisabled', 7, 'ann', true]],
			$this->calls->getArrayCopy()
		);
	}

	/**
	 * The holder preview resolves the anchor at the end of the share's url and strips the body.
	 *
	 * @return void
	 */
	public function testHolderPreviewResolvesTheUrlAnchor(): void {
		$reader = new class {
			public function read(array $link): array {
				return ['subject' => ['title' => 'Bouwvergunning', '@self' => ['uuid' => 'case-1', 'owner' => 'ann'], '@internal' => 1]];
			}
		};
		$shares = $this->shares(
			objects: $this->objects(
				rows: [
					['accessLinkId' => 1, 'accessLinkUrl' => ''],
					['accessLinkId' => 2, 'accessLinkUrl' => ' https://nc.example/apps/openregister/l/ANCHOR-2 '],
				]
			),
			links: $this->links(link: ['id' => 2]),
			reader: $reader
		);

		$this->assertSame(
			['subject' => ['title' => 'Bouwvergunning', '@self' => ['uuid' => 'case-1']]],
			$shares->holderPreview(linkId: 2, caseId: 'case-1')
		);
		$this->assertSame([['resolve', 'ANCHOR-2']], $this->callsOf(kind: 'resolve'));

		$this->assertNull($shares->holderPreview(linkId: 1, caseId: 'case-1'));
		$this->assertNull($shares->holderPreview(linkId: 3, caseId: 'case-1'));
		$this->assertCount(1, $this->callsOf(kind: 'resolve'));
	}

	/**
	 * Collecting a comment stamps the note on the share record.
	 *
	 * @return void
	 */
	public function testMarkCollectedStampsTheNote(): void {
		$shares = $this->shares(objects: $this->objects(found: ['id' => 'share-1', 'caseId' => 'case-1']));

		$this->assertTrue($shares->markCollected(shareId: 'share-1', noteId: 42));
		$this->assertSame(
			[
				['find', 'share-1', 5, 9],
				['saveObject', ['id' => 'share-1', 'caseId' => 'case-1', 'lastCollectedNote' => 42], 5, 9],
			],
			$this->calls->getArrayCopy()
		);
	}

	/**
	 * Collecting fails without configuration, for an unknown share, and when the save fails.
	 *
	 * @return void
	 */
	public function testMarkCollectedRefusals(): void {
		$this->assertFalse($this->shares(objects: $this->objects(found: ['id' => 'share-1']), config: [])->markCollected(shareId: 'share-1', noteId: 1));
		$this->assertFalse($this->shares(objects: $this->objects(found: null))->markCollected(shareId: 'share-1', noteId: 1));
		$this->assertSame([], $this->callsOf(kind: 'saveObject'));

		$this->logger->expects($this->once())->method('error')->with(
			'CaseLinkShares: could not record the collected comment on the share',
			['shareId' => 'share-1', 'exception' => 'write down']
		);
		$this->assertFalse(
			$this->shares(objects: $this->objects(found: ['id' => 'share-1'], fail: new RuntimeException('write down')))->markCollected(shareId: 'share-1', noteId: 1)
		);
	}

	/**
	 * A minted link is recorded as a link share carrying the link and its documents; extra keys never override it.
	 *
	 * @return void
	 */
	public function testStoreRecordsTheMintedLink(): void {
		$this->logger->expects($this->once())->method('info')->with(
			'Dossiq: case access link minted',
			['caseId' => 'case-1', 'createdBy' => 'ann', 'capabilities' => ['read', 'comment'], 'documents' => 1]
		);

		$stored = $this->shares(objects: $this->objects())->store(
			caseId: 'case-1',
			label: 'Aanvrager',
			createdBy: 'ann',
			link: ['id' => 7, 'uuid' => 'link-7', 'url' => 'https://nc.example/l/A', 'expiresAt' => '2026-12-31', 'capabilities' => ['read', 'comment']],
			documents: [['fileId' => 3, 'linkId' => 8]],
			extra: ['recipientEmail' => 'a@example.org', 'status' => 'overridden']
		);

		$expected = [
			'recipientEmail' => 'a@example.org',
			'status' => 'active',
			'caseId' => 'case-1',
			'shareType' => 'link',
			'permissionLevel' => 'bekijken_reageren',
			'label' => 'Aanvrager',
			'createdBy' => 'ann',
			'accessLinkId' => 7,
			'accessLinkUuid' => 'link-7',
			'accessLinkUrl' => 'https://nc.example/l/A',
			'capabilities' => 'read,comment',
			'expiresAt' => '2026-12-31',
			'sharedDocuments' => '[{"fileId":3,"linkId":8}]',
		];
		$this->assertSame([['saveObject', $expected, 5, 9]], $this->calls->getArrayCopy());
		$this->assertSame($expected + ['id' => 'share-1'], $stored);
	}

	/**
	 * A read-only link is recorded with the view-only permission level.
	 *
	 * @return void
	 */
	public function testStoreRecordsAReadOnlyLinkAsViewOnly(): void {
		$this->shares(objects: $this->objects())->store(caseId: 'case-1', label: '', createdBy: 'ann', link: ['capabilities' => ['read']], documents: [], extra: []);

		$record = $this->callsOf(kind: 'saveObject')[0][1];
		$this->assertSame('bekijken', $record['permissionLevel']);
		$this->assertSame(0, $record['accessLinkId']);
		$this->assertSame('[]', $record['sharedDocuments']);
	}

	/**
	 * Storing refuses without OpenRegister, without a share schema, and when the write fails.
	 *
	 * @return void
	 */
	public function testStoreRefusals(): void {
		$this->assertSame(
			['error' => 'OpenRegister is not available'],
			$this->shares(objects: $this->objects(), installed: false)->store(caseId: 'case-1', label: '', createdBy: 'ann', link: [], documents: [], extra: [])
		);

		$unconfigured = $this->shares(objects: $this->objects(), config: ['register' => '5'])->store(caseId: 'case-1', label: '', createdBy: 'ann', link: [], documents: [], extra: []);
		$this->assertSame(CaseLinkShares::REASON_NOT_CONFIGURED, $unconfigured['reason']);

		$failed = $this->shares(objects: $this->objects(fail: new RuntimeException('write down')))->store(caseId: 'case-1', label: '', createdBy: 'ann', link: [], documents: [], extra: []);
		$this->assertSame(
			['error' => 'The link could not be recorded on the case, so it was withdrawn again.', 'reason' => CaseLinkShares::REASON_ROLLED_BACK],
			$failed
		);
	}

	/**
	 * Withdrawing a share revokes the case link and every document link, and names those OpenRegister refused.
	 *
	 * @return void
	 */
	public function testRevokeLinksOfRevokesTheCaseAndDocumentLinks(): void {
		$shares = $this->shares(objects: $this->objects(), links: $this->links(refuse: [12]));

		$refused = $shares->revokeLinksOf(
			share: ['accessLinkId' => 10, 'sharedDocuments' => '[{"linkId":11},{"linkId":12},{"linkId":0},"junk"]'],
			userId: 'ann'
		);

		$this->assertSame([12], $refused);
		$this->assertSame([['revoke', 10, 'ann'], ['revoke', 11, 'ann'], ['revoke', 12, 'ann']], $this->callsOf(kind: 'revoke'));
	}

	/**
	 * Withdrawing a just-minted link uses the link id and the documents as given.
	 *
	 * @return void
	 */
	public function testRevokeMintedRevokesTheMintedLinks(): void {
		$shares = $this->shares(objects: $this->objects(), links: $this->links());

		$this->assertSame([], $shares->revokeMinted(link: ['id' => 20], documents: [['linkId' => 21]], userId: 'bob'));
		$this->assertSame([], $shares->revokeMinted(link: [], documents: [], userId: 'bob'));
		$this->assertSame([['revoke', 20, 'bob'], ['revoke', 21, 'bob']], $this->callsOf(kind: 'revoke'));
	}
}
