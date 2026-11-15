<?php

/**
 * CaseStatusStore status record read tests.
 *
 * Pins what findStatusRecords() asks OpenRegister and what it answers for
 * every configuration, every row shape and a failing read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Characterisation tests for CaseStatusStore::findStatusRecords().
 *
 * @covers \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 */
class CaseStatusStoreRecordsTest extends TestCase {

	/**
	 * The searchObjects() queries sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The log lines written, as [level, message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A store over an object service answering the given value, or throwing it.
	 *
	 * @param mixed                 $answer The searchObjects() answer, or an exception.
	 * @param array<string, string> $config The app config.
	 * @param bool                  $withService Whether OpenRegister is there.
	 *
	 * @return CaseStatusStore The store.
	 */
	private function store(mixed $answer, array $config=['register' => '4', 'status_record_schema' => '9'], bool $withService=true): CaseStatusStore {
		$queries = &$this->queries;
		$objectService = new class($answer, $queries) {
			/**
			 * @param mixed                            $answer  The answer.
			 * @param array<int, array<string, mixed>> $queries The shared query log.
			 */
			public function __construct(private mixed $answer, private array &$queries) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed The answer.
			 */
			public function searchObjects(array $query): mixed {
				$this->queries[] = $query;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withService === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * @param array<int, array<int, mixed>> $logged The shared log.
			 */
			public function __construct(private array &$logged) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->logged[] = [$level, (string)$message, $context];
			}
		};

		return new CaseStatusStore($settings, $this->createMock(StatusTypeLookup::class), $logger);
	}//end store()

	/**
	 * The records come back as arrays, from arrays and serialisable entities, in order.
	 *
	 * @return void
	 */
	public function testTheRecordsAndTheirQuery(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, string> The record.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'rec-2'];
			}
		};

		self::assertSame([['id' => 'rec-1'], ['id' => 'rec-2']], $this->store([['id' => 'rec-1'], $entity])->findStatusRecords('case-1'));
		self::assertSame([['@self' => ['register' => 4, 'schema' => 9], 'case' => 'case-1']], $this->queries);
		self::assertSame([], $this->store(null)->findStatusRecords('case-1'));
		self::assertSame([], $this->store([])->findStatusRecords('case-1'));
	}//end testTheRecordsAndTheirQuery()

	/**
	 * Without OpenRegister or its configuration the history cannot be read, and nothing is asked.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreReadsNothing(): void {
		self::assertNull($this->store([], withService: false)->findStatusRecords('case-1'));
		self::assertNull($this->store([], ['register' => '', 'status_record_schema' => '9'])->findStatusRecords('case-1'));
		self::assertNull($this->store([], ['register' => '4', 'status_record_schema' => ''])->findStatusRecords('case-1'));
		self::assertSame([], $this->queries);
	}//end testAnUnconfiguredStoreReadsNothing()

	/**
	 * A failing read is logged at error and answers null, not an empty history.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersNull(): void {
		self::assertNull($this->store(new RuntimeException('down'))->findStatusRecords('case-1'));
		self::assertSame(
			[['error', 'StatusTransitionService: replay searchObjects failed', ['exception' => 'down', 'caseId' => 'case-1']]],
			$this->logged
		);
	}//end testAFailingReadAnswersNull()
}//end class
