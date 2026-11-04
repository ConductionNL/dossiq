<?php

/**
 * Tests for recording what became of a letter, and listing a case's letters.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A status from integriq lands on the stored letter and, when it names a case, on its timeline.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxService
 * @covers \OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class BerichtenboxDeliveryStatusTest extends TestCase {

	/**
	 * What was saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * What reached the timeline.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * The queries the store was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A delivered letter is saved with its status and written on its case as an internal entry.
	 *
	 * @return void
	 */
	public function testADeliveredLetterIsRecorded(): void {
		$service = $this->service(stored: [['caseId' => 'case-1', 'subject' => 'Uw aanvraag', 'externalMessageId' => 'm-1']]);

		$this->assertTrue(condition: $service->recordDeliveryStatus(externalMessageId: 'm-1', status: 'delivered'));
		$this->assertSame(expected: 'delivered', actual: $this->saved[0]['status']);
		$this->assertSame(expected: '', actual: $this->saved[0]['lastError']);
		$this->assertFalse(condition: $this->saved[0]['simulated']);
		$this->assertArrayNotHasKey(key: 'readAt', array: $this->saved[0]);
		$this->assertSame(
			expected: [['filters' => ['register' => 7, 'schema' => 9, 'externalMessageId' => 'm-1']]],
			actual: $this->queries
		);
		$this->assertSame(expected: 'Uw aanvraag -- delivered', actual: $this->entries[0]['message']);
		$this->assertSame(expected: CaseTimeline::INTERNAL, actual: $this->entries[0]['visibility']);
		$this->assertSame(
			expected: ['subject' => 'Uw aanvraag', 'messageId' => 'm-1', 'status' => 'delivered'],
			actual: $this->entries[0]['fields']
		);
	}//end testADeliveredLetterIsRecorded()

	/**
	 * A read letter gets a read time; a simulated one says so.
	 *
	 * @return void
	 */
	public function testAReadLetterGetsAReadTime(): void {
		$service = $this->service(stored: [new class {
			/**
			 * The stored letter.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['caseId' => 'case-2', 'subject' => 'Besluit'];
			}
		},
		]);

		$this->assertTrue(condition: $service->recordDeliveryStatus(externalMessageId: 'm-2', status: 'read', simulated: true));
		$this->assertArrayHasKey(key: 'readAt', array: $this->saved[0]);
		$this->assertTrue(condition: $this->saved[0]['simulated']);
	}//end testAReadLetterGetsAReadTime()

	/**
	 * A failed letter names the provider's reason, or says it gave none; a letter without a case writes no entry.
	 *
	 * @return void
	 */
	public function testAFailedLetterNamesItsReason(): void {
		$this->service(stored: [['caseId' => 'case-3', 'subject' => 'Brief']])
			->recordDeliveryStatus(externalMessageId: 'm-3', status: 'failed', lastError: 'mailbox closed');
		$this->service(stored: [['caseId' => 'case-3', 'subject' => 'Brief']])
			->recordDeliveryStatus(externalMessageId: 'm-3', status: 'failed');
		$this->service(stored: [['subject' => 'Brief']])
			->recordDeliveryStatus(externalMessageId: 'm-3', status: 'failed');

		$this->assertSame(
			expected: ['Brief -- not delivered: mailbox closed', 'Brief -- not delivered: the provider gave no reason'],
			actual: array_column($this->entries, 'message')
		);
		$this->assertCount(expectedCount: 3, haystack: $this->saved);
	}//end testAFailedLetterNamesItsReason()

	/**
	 * Nothing is written for an empty id or status, a missing store, an unknown letter or an unreadable one.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutALetter(): void {
		$this->assertFalse(condition: $this->service(stored: [['caseId' => 'c']])->recordDeliveryStatus(externalMessageId: ' ', status: 'read'));
		$this->assertFalse(condition: $this->service(stored: [['caseId' => 'c']])->recordDeliveryStatus(externalMessageId: 'm', status: ''));
		$withoutStore = $this->service(stored: [['caseId' => 'c']], installed: false);
		$this->assertFalse(condition: $withoutStore->recordDeliveryStatus(externalMessageId: 'm', status: 'read'));
		$this->assertFalse(condition: $this->service(stored: [])->recordDeliveryStatus(externalMessageId: 'm', status: 'read'));
		$this->assertFalse(condition: $this->service(stored: ['not a letter'])->recordDeliveryStatus(externalMessageId: 'm', status: 'read'));

		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertSame(expected: [], actual: $this->entries);
	}//end testNothingIsWrittenWithoutALetter()

	/**
	 * A case's letters are read from the message schema; without the store there are none.
	 *
	 * @return void
	 */
	public function testACasesLettersAreListed(): void {
		$letters = $this->service(stored: [['caseId' => 'case-1']])->getMessagesForCase(caseId: 'case-1');
		$this->assertSame(expected: [['caseId' => 'case-1']], actual: $letters);
		$this->assertSame(expected: [['filters' => ['register' => 7, 'schema' => 9, 'caseId' => 'case-1']]], actual: $this->queries);
		$this->assertSame(expected: [], actual: $this->service(stored: [['caseId' => 'case-1']], installed: false)->getMessagesForCase(caseId: 'case-1'));
	}//end testACasesLettersAreListed()

	/**
	 * The service over a store holding the given letters.
	 *
	 * @param array<int, mixed> $stored    What the store finds.
	 * @param bool              $installed Whether OpenRegister is installed.
	 *
	 * @return BerichtenboxService
	 */
	private function service(array $stored, bool $installed = true): BerichtenboxService {
		$objects = $this->createMock(originalClassName: 'OCA\\OpenRegister\\Contract\\ObjectServiceInterface');
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest) use ($stored): array {
				$this->queries[] = $config;
				return $stored;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest): object {
				$this->saved[] = $object;
				return $this->createMock(originalClassName: 'OCA\\OpenRegister\\Contract\\ObjectEntityInterface');
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '7', 'berichtenbox_message_schema' => '9'][$key] ?? ''
		);

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		if ($installed === false) {
			$apps = $this->createMock(originalClassName: IAppManager::class);
			$apps->method('getInstalledApps')->willReturn([]);
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$timeline = $this->createMock(originalClassName: CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message, array $fields = [], string $visibility = 'internal'): string {
				$this->entries[] = ['caseId' => $caseId, 'message' => $message, 'fields' => $fields, 'visibility' => $visibility];
				return 'entry';
			}
		);

		$account = $this->createMock(originalClassName: BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new BerichtenboxService(
			settingsService: $settings,
			appManager: $apps,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			adapter: $this->createMock(originalClassName: BerichtenboxAdapterInterface::class),
			journal: new BerichtenboxJournal(timeline: $timeline),
			serviceAccount: $account,
		);
	}//end service()
}//end class
