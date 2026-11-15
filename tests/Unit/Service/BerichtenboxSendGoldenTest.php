<?php

/**
 * Golden test for sending a letter through BerichtenboxService.
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
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every adapter call, store write, timeline entry and log line of sendMessage(), per case.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxService
 * @covers \OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class BerichtenboxSendGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const BSN = '111222333';

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The letters, what the adapter answers, and whether OpenRegister is installed.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool|null}>
	 */
	public static function letters(): array {
		$letter = ['caseId' => 'case-1', 'bsn' => self::BSN, 'subject' => 'Uw aanvraag', 'body' => 'Wij hebben uw aanvraag ontvangen.', 'typeCode' => 'T1'];
		$sent = ['messageId' => 'msg-9', 'status' => 'delivered', 'sentAt' => '2026-11-02T10:00:00+01:00'];

		return [
			'sent' => [$letter, $sent, true],
			'sent with an attachment and a category' => [$letter + ['attachmentFileId' => '42', 'category' => 'besluit'], $sent, true],
			'sent without a status' => [$letter, ['messageId' => 'msg-8', 'sentAt' => '2026-11-02T11:00:00+01:00'], true],
			'refused with a code' => [$letter, ['refused' => true, 'error' => 'opted out', 'code' => 'opt-out'], true],
			'refused without a code' => [$letter + ['category' => 'statutory'], ['refused' => true], true],
			'an invalid bsn' => [array_merge($letter, ['bsn' => '123']), $sent, true],
			'no bsn, subject or body' => [array_merge($letter, ['bsn' => '', 'subject' => '', 'body' => '']), $sent, true],
			'an html body' => [array_merge($letter, ['body' => '<b>Hallo</b>']), $sent, true],
			'no openregister' => [$letter, $sent, false],
			'openregister cannot be reached' => [$letter, $sent, null],
		];
	}//end letters()

	/**
	 * The calls and the answer match the recording.
	 *
	 * @param array<string, mixed> $letter    The letter.
	 * @param array<string, mixed> $result    What the adapter answers.
	 * @param bool|null            $installed Whether OpenRegister is installed; null when installed but unreachable.
	 *
	 * @dataProvider letters
	 *
	 * @return void
	 */
	public function testSendingMatchesTheRecording(array $letter, array $result, ?bool $installed): void {
		$answer = $this->service(result: $result, installed: $installed)->sendMessage(...$letter);

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'berichtenbox-send/' . $name, value: ['calls' => $this->log, 'answer' => $answer]);
	}//end testSendingMatchesTheRecording()

	/**
	 * The service over recording collaborators.
	 *
	 * @param array<string, mixed> $result    What the adapter answers.
	 * @param bool|null            $installed Whether OpenRegister is installed; null when installed but unreachable.
	 *
	 * @return BerichtenboxService
	 */
	private function service(array $result, ?bool $installed): BerichtenboxService {
		$adapter = $this->createMock(originalClassName: BerichtenboxAdapterInterface::class);
		$adapter->method('sendMessage')->willReturnCallback(
			function (mixed ...$args) use ($result): array {
				$this->log[] = ['adapter' => $args];
				return $result;
			}
		);

		$objects = $this->createMock(originalClassName: 'OCA\\OpenRegister\\Contract\\ObjectServiceInterface');
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest): object {
				$this->log[] = ['saveObject' => ['object' => $object, 'named' => $rest]];
				$entity = $this->createMock(originalClassName: 'OCA\\OpenRegister\\Contract\\ObjectEntityInterface');
				$entity->method('jsonSerialize')->willReturn(array_merge($object, ['id' => 'message-1']));

				return $entity;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				$this->log[] = ['getConfigValue' => $key];
				return ['register' => '7', 'berichtenbox_message_schema' => '9'][$key] ?? '';
			}
		);

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		if ($installed === false) {
			$apps = $this->createMock(originalClassName: IAppManager::class);
			$apps->method('getInstalledApps')->willReturn([]);
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		if ($installed === null) {
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willThrowException(new \RuntimeException('container down'));
		}

		$timeline = $this->createMock(originalClassName: CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message, array $fields = [], string $visibility = 'internal'): string {
				$this->log[] = ['timeline' => compact('caseId', 'kind', 'message', 'fields', 'visibility')];
				return 'entry-1';
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$account = $this->createMock(originalClassName: BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new BerichtenboxService(
			settingsService: $settings,
			appManager: $apps,
			container: $container,
			logger: $logger,
			adapter: $adapter,
			journal: new BerichtenboxJournal(timeline: $timeline),
			serviceAccount: $account,
		);
	}//end service()
}//end class
