<?php

/**
 * How each channel of a requester notice answers when it cannot take it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Notification\RequesterNoticeSender
 * @uses \OCA\Dossiq\Service\Notification\NoticeDelivery
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 */
final class RequesterNoticeSenderChannelsTest extends TestCase {
	/**
	 * The store calls, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * What the portal save answers: a row, null, or an error to throw.
	 *
	 * @var array<string, mixed>|RuntimeException|null
	 */
	public array|RuntimeException|null $portalAnswer = ['id' => 'msg-1'];

	/**
	 * The stored case, as find() answers it.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $storedCase = null;

	/**
	 * The log lines, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * A sender over doubles for every transport.
	 *
	 * @param TermNoticeSender|null    $email    The e-mail transport.
	 * @param BerichtenboxService|null $post     Digital post.
	 * @param array<string, string>    $config   Config overrides.
	 * @param bool                     $portaliq Whether portaliq is installed.
	 * @param CaseFieldWriter|null     $writer   The case writer.
	 *
	 * @return RequesterNoticeSender
	 */
	private function sender(
		?TermNoticeSender $email = null,
		?BerichtenboxService $post = null,
		array $config = [],
		bool $portaliq = true,
		?CaseFieldWriter $writer = null,
	): RequesterNoticeSender {
		$config += ['register' => 'dossiq', 'case_schema' => 'case', 'portaal_bericht_schema' => 'portaalBericht'];
		$test = $this;
		$store = new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param RequesterNoticeSenderChannelsTest $test The test.
			 */
			public function __construct(private RequesterNoticeSenderChannelsTest $test) {
			}

			/**
			 * Save an object, answering what the test set.
			 *
			 * @param array           $object   The object.
			 * @param int|string|null $register The register.
			 * @param int|string|null $schema   The schema.
			 * @param string|null     $uuid     The uuid.
			 *
			 * @return array<string, mixed>|null
			 */
			public function saveObject(array $object, int|string|null $register = null, int|string|null $schema = null, ?string $uuid = null): ?array {
				$this->test->calls[] = ['save', ['schema' => $schema, 'object' => $object]];
				if ($this->test->portalAnswer instanceof RuntimeException) {
					throw $this->test->portalAnswer;
				}

				return $this->test->portalAnswer;
			}

			/**
			 * The stored case.
			 *
			 * @param string          $id       The id.
			 * @param int|string|null $register The register.
			 * @param int|string|null $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, int|string|null $register = null, int|string|null $schema = null): ?array {
				$this->test->calls[] = ['find', ['id' => $id, 'schema' => $schema]];

				return $this->test->storedCase;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $config[$key] ?? '');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => ($app === 'portaliq' && $portaliq === true));
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logged[] = [$level, $message];
			});
		}

		return new RequesterNoticeSender(
			email: ($email ?? $this->createMock(TermNoticeSender::class)),
			digitalPost: $post,
			settings: $settings,
			appManager: $apps,
			writer: ($writer ?? $this->createMock(CaseFieldWriter::class)),
			logger: $logger,
		);
	}//end sender()

	/**
	 * The rendered notice.
	 *
	 * @return array{subject: string, body: string}
	 */
	private static function rendered(): array {
		return ['subject' => 'Onderwerp', 'body' => 'Tekst'];
	}//end rendered()

	/**
	 * Unknown templates are a stage notice.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateIsAStage(): void {
		$sender = $this->sender();

		$this->assertSame('stage', $sender->momentFor('something-else'));
		$this->assertSame('transfer', $sender->momentFor('doorzending'));
	}//end testAnUnknownTemplateIsAStage()

	/**
	 * An unconfigured portal inbox is a refusal, and the notice is not sent.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredPortalInboxRefuses(): void {
		$result = $this->sender(null, null, ['portaal_bericht_schema' => ''])
			->send(['id' => 'case-1', 'portalSubject' => 'ps-1'], 'extension', self::rendered(), 'extension');

		$this->assertSame('not-sent', $result['status']);
		$this->assertSame('portal-inbox-unavailable', $result['reasonCode']);
		$this->assertSame([['channel' => 'portal-inbox', 'reasonCode' => 'portal-inbox-unavailable', 'reason' => 'The portal inbox is not configured, so the notice could not be put there.']], $result['channelsTried']);
	}//end testAnUnconfiguredPortalInboxRefuses()

	/**
	 * A throwing portal save, and a save that yields no id, both refuse with portal-inbox-failed.
	 *
	 * @return void
	 */
	public function testAPortalInboxThatFailsRefuses(): void {
		$this->portalAnswer = new RuntimeException('down');
		$thrown = $this->sender()->send(['id' => 'case-1', 'portalSubject' => 'ps-1'], 'extension', self::rendered(), 'extension');
		$this->assertSame('portal-inbox-failed', $thrown['reasonCode']);
		$this->assertContains(['error', 'Dossiq requester notice: the portal inbox refused it'], $this->logged);

		$this->portalAnswer = ['title' => 'no id'];
		$noId = $this->sender()->send(['id' => 'case-1', 'portalSubject' => 'ps-1'], 'extension', self::rendered(), 'extension');
		$this->assertSame('portal-inbox-failed', $noId['reasonCode']);
	}//end testAPortalInboxThatFailsRefuses()

	/**
	 * A portal message whose id sits only in @self is sent under that id, with the notice text.
	 *
	 * @return void
	 */
	public function testAPortalIdMayComeFromSelf(): void {
		$this->portalAnswer = ['@self' => ['id' => 'self-7']];

		$result = $this->sender()->send(['id' => 'case-1', 'identifier' => 'Z-1', 'portalSubject' => ' ps-1 '], 'extension', self::rendered(), 'extension');

		$this->assertSame(['sent', 'portal-inbox', 'self-7'], [$result['status'], $result['channel'], $result['messageId']]);
		$saved = $this->calls[0][1];
		$this->assertSame('portaalBericht', $saved['schema']);
		$this->assertSame(['case-1', 'Z-1', 'ps-1', 'Onderwerp', 'Tekst'], [
			$saved['object']['caseId'],
			$saved['object']['caseReference'],
			$saved['object']['recipientRef'],
			$saved['object']['subject'],
			$saved['object']['content'],
		]);
	}//end testAPortalIdMayComeFromSelf()

	/**
	 * Digital post gets the category of its moment and template.
	 *
	 * @return void
	 */
	public function testDigitalPostCategories(): void {
		$categories = [];
		$post = $this->createMock(BerichtenboxService::class);
		$post->method('sendMessage')->willReturnCallback(
			static function (string $caseId, string $bsn, string $subject, string $body, string $typeCode, ?string $attachmentFileId = null, string $category = 'case-update') use (&$categories): array {
				$categories[] = [$typeCode, $category, $bsn];

				return ['externalMessageId' => 'ext-1', 'sentAt' => '2026-09-01T10:00:00+00:00'];
			}
		);
		$case = ['id' => 'case-1', 'initiatorType' => 'person', 'initiatorSourceId' => ' 123456789 '];
		$sender = $this->sender(null, $post);

		$sent = $sender->send($case, 'niet-ontvankelijk', self::rendered(), 'decision');
		$sender->send($case, 'ontvangstbevestiging', self::rendered(), 'acknowledgement');
		$sender->send($case, 'extension', self::rendered(), 'extension');

		$this->assertSame(['digital-post', 'ext-1', '2026-09-01T10:00:00+00:00'], [$sent['channel'], $sent['messageId'], $sent['sentAt']]);
		$this->assertSame(
			[['niet-ontvankelijk', 'besluit', '123456789'], ['ontvangstbevestiging', 'statutory', '123456789'], ['extension', 'case-update', '123456789']],
			$categories
		);
	}//end testDigitalPostCategories()

	/**
	 * Digital post is only offered to a person with a nine-digit BSN.
	 *
	 * @return void
	 */
	public function testDigitalPostNeedsAPersonWithABsn(): void {
		$post = $this->createMock(BerichtenboxService::class);
		$post->expects($this->never())->method('sendMessage');
		$sender = $this->sender(null, $post);

		$this->assertSame('no-channel', $sender->send(['id' => 'c', 'initiatorType' => 'organisation', 'initiatorSourceId' => '123456789'], 'extension', self::rendered(), 'extension')['reasonCode']);
		$this->assertSame('no-channel', $sender->send(['id' => 'c', 'initiatorType' => 'person', 'initiatorSourceId' => '12345678'], 'extension', self::rendered(), 'extension')['reasonCode']);
	}//end testDigitalPostNeedsAPersonWithABsn()

	/**
	 * Digital post that throws or gives no tracked id refuses with digital-post-failed.
	 *
	 * @return void
	 */
	public function testDigitalPostWithoutATrackedIdIsNotSent(): void {
		$case = ['id' => 'case-1', 'initiatorType' => 'person', 'initiatorSourceId' => '123456789'];

		$throwing = $this->createMock(BerichtenboxService::class);
		$throwing->method('sendMessage')->willThrowException(new RuntimeException('down'));
		$thrown = $this->sender(null, $throwing)->send($case, 'extension', self::rendered(), 'extension');
		$this->assertSame(['digital-post-failed', 'Digital post did not accept the notice.'], [$thrown['reasonCode'], $thrown['reason']]);

		$empty = $this->createMock(BerichtenboxService::class);
		$empty->method('sendMessage')->willReturn(['externalMessageId' => '  ']);
		$noId = $this->sender(null, $empty)->send($case, 'extension', self::rendered(), 'extension');
		$this->assertSame(['digital-post-failed', 'Digital post gave no tracked message id, so the notice counts as not sent.'], [$noId['reasonCode'], $noId['reason']]);

		$refused = $this->createMock(BerichtenboxService::class);
		$refused->method('sendMessage')->willReturn(['refused' => true]);
		$this->assertSame(['refused', 'Integriq refused the notice.'], array_values(array_intersect_key(
			$this->sender(null, $refused)->send($case, 'extension', self::rendered(), 'extension'),
			['reasonCode' => 1, 'reason' => 1]
		)));
	}//end testDigitalPostWithoutATrackedIdIsNotSent()

	/**
	 * E-mail goes to the first valid address in priority order, lowercased, and passes the notice through.
	 *
	 * @return void
	 */
	public function testEmailPicksTheFirstValidAddress(): void {
		$email = $this->createMock(TermNoticeSender::class);
		$email->expects($this->once())->method('send')
			->with('extension', 'inst-1', 'verzoeker@example.nl', 'case-1', 'Onderwerp', 'Tekst', 'dedupe-1', $this->anything())
			->willReturn(['messageId' => 'm-1', 'sentOn' => '2026-09-01', 'duplicate' => true]);

		$result = $this->sender($email, null, [], false)->send(
			['id' => 'case-1', 'verzoekerEmail' => 'not-an-address', 'wooRequest' => ['verzoekerEmail' => ' Verzoeker@Example.NL '], 'email' => 'other@example.nl'],
			'extension',
			self::rendered(),
			'extension',
			['recipient' => '', 'instanceId' => 'inst-1', 'dedupeKey' => 'dedupe-1']
		);

		$this->assertSame(['sent', 'email', 'm-1', '2026-09-01', true], [$result['status'], $result['channel'], $result['messageId'], $result['sentAt'], $result['duplicate']]);
	}//end testEmailPicksTheFirstValidAddress()

	/**
	 * A refused e-mail carries the exception's reason code and message.
	 *
	 * @return void
	 */
	public function testARefusedEmailCarriesItsReason(): void {
		$email = $this->createMock(TermNoticeSender::class);
		$email->method('send')->willThrowException(new NoticeNotSentException('opted-out', 'The requester opted out.'));

		$result = $this->sender($email, null, [], false)->send(['id' => 'case-1', 'email' => 'a@example.nl'], 'extension', self::rendered(), 'extension');

		$this->assertSame(['not-sent', 'opted-out', 'The requester opted out.'], [$result['status'], $result['reasonCode'], $result['reason']]);
	}//end testARefusedEmailCarriesItsReason()

	/**
	 * The record keeps caller extras first, is appended to the stored list and written through the writer.
	 *
	 * @return void
	 */
	public function testTheRecordIsAppendedToTheCase(): void {
		$this->storedCase = ['id' => 'case-1', 'outboundCommunications' => [['template' => 'earlier']]];
		$written = [];
		$writer = $this->createMock(CaseFieldWriter::class);
		$writer->expects($this->once())->method('write')->willReturnCallback(
			static function (object $objectService, string $register, string $schema, array $case, array $changes) use (&$written): void {
				$written = [$register, $schema, $case, $changes];
			}
		);

		$result = $this->sender(null, null, [], false, $writer)->send(['id' => 'case-1'], 'extension', self::rendered(), 'extension', ['recordExtras' => ['status' => 'overridden', 'note' => 'x']]);

		$this->assertSame('overridden', $result['record']['status']);
		$this->assertSame('x', $result['record']['note']);
		$this->assertSame('no-channel', $result['record']['reasonCode']);
		$this->assertSame(['dossiq', 'case', ['id' => 'case-1']], array_slice($written, 0, 3));
		$this->assertSame([['template' => 'earlier'], $result['record']], $written[3]['outboundCommunications']);
	}//end testTheRecordIsAppendedToTheCase()

	/**
	 * A failing record write is logged and the result still returned.
	 *
	 * @return void
	 */
	public function testAFailedRecordWriteIsLogged(): void {
		$writer = $this->createMock(CaseFieldWriter::class);
		$writer->method('write')->willThrowException(new RuntimeException('conflict'));

		$result = $this->sender(null, null, [], false, $writer)->send(['id' => 'case-1'], 'extension', self::rendered(), 'extension');

		$this->assertSame('not-sent', $result['status']);
		$this->assertContains(['error', 'Dossiq requester notice: case {case} could not record the notice'], $this->logged);
		$this->assertContains(['info', 'Dossiq requester notice: {template} is {status}'], $this->logged);
	}//end testAFailedRecordWriteIsLogged()
}//end class
