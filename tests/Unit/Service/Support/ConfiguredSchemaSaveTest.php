<?php

/**
 * The saves to a configured schema in the mandaat and accrual services
 *
 * Pins, for each of the three services that persist to "the register and the
 * schema a config key names", what a save answers when the schema is not
 * configured, when the store saves, when the store answers no array, and what
 * a failed save logs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Mandaat\MandaatRepository;
use OCA\Dossiq\Service\MandaatEscalatieService;
use OCA\Dossiq\Service\Money\AccrualNoticeService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What each save to a configured schema answers and logs.
 *
 * @covers \OCA\Dossiq\Service\Mandaat\MandaatRepository
 * @covers \OCA\Dossiq\Service\MandaatEscalatieService
 * @covers \OCA\Dossiq\Service\Money\AccrualNoticeService
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
final class ConfiguredSchemaSaveTest extends TestCase {
	/**
	 * The errors logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $errors = [];

	/**
	 * The saveObject calls the store received, as [object, register, schema, uuid].
	 *
	 * @var array<int, array>
	 */
	private array $saves = [];

	/**
	 * The three services, with the message and context key each logs a failed save under.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function services(): array {
		return [
			'mandaat repository' => ['repository', 'Mandaat import persist failed', 'key'],
			'mandaat escalation' => ['escalation', 'Mandaat persist failed', 'key'],
			'accrual notices'    => ['accrual', 'AccrualNoticeService persist failed', 'schemaConfigKey'],
		];
	}//end services()

	/**
	 * Save one payload through the named service.
	 *
	 * @param string      $which The service.
	 * @param object|null $store The object service, or null when OpenRegister is absent.
	 *
	 * @return array
	 */
	private function save(string $which, ?object $store, array $config = ['register' => 'reg', 'the_schema' => 'sch']): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		$payload = ['title' => 'mandaat'];
		if ($which === 'repository') {
			return (new MandaatRepository(settingsService: $settings, logger: $logger))->save('the_schema', $payload);
		}

		$service = new MandaatEscalatieService(settingsService: $settings, logger: $logger);
		$method  = 'save';
		if ($which === 'accrual') {
			$service = new AccrualNoticeService(settingsService: $settings, termService: $this->createMock(TermijnService::class), logger: $logger);
			$method  = 'saveSchema';
		}

		return (new \ReflectionMethod($service, $method))->invoke($service, 'the_schema', $payload);
	}//end save()

	/**
	 * A store that answers with the given value, or throws it.
	 *
	 * @param mixed $answer What saveObject answers, or the Throwable it throws.
	 *
	 * @return object
	 */
	private function store(mixed $answer): object {
		$test = $this;
		return new class ($answer, $test) {
			/**
			 * Constructor.
			 *
			 * @param mixed  $answer The answer.
			 * @param object $test   The test that records the call.
			 */
			public function __construct(private mixed $answer, private object $test) {
			}

			/**
			 * Record the call and answer.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, mixed $register, mixed $schema, ?string $uuid = null): mixed {
				$this->test->recordSave([$object, $register, $schema, $uuid]);
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end store()

	/**
	 * Record one saveObject call.
	 *
	 * @param array $call The call.
	 *
	 * @return void
	 */
	public function recordSave(array $call): void {
		$this->saves[] = $call;
	}//end recordSave()

	/**
	 * A configured schema saves the payload and answers with what the store saved.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testASavedObjectIsTheAnswer(string $which): void {
		$this->assertSame(['id' => 'm-1'], $this->save($which, $this->store(['id' => 'm-1'])));
		$this->assertSame([[['title' => 'mandaat'], 'reg', 'sch', null]], $this->saves);
		$this->assertSame([], $this->errors);
	}//end testASavedObjectIsTheAnswer()

	/**
	 * A store answer that is no object answers the payload.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testAnUnrepresentableSaveAnswersThePayload(string $which): void {
		$this->assertSame(['title' => 'mandaat'], $this->save($which, $this->store('not an object')));
		$this->assertCount(1, $this->saves);
	}//end testAnUnrepresentableSaveAnswersThePayload()

	/**
	 * Without OpenRegister, register or schema nothing is saved and the payload is the answer.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testAnUnconfiguredSchemaSavesNothing(string $which): void {
		$store = $this->store(['id' => 'm-1']);
		$this->assertSame(['title' => 'mandaat'], $this->save($which, null));
		$this->assertSame(['title' => 'mandaat'], $this->save($which, $store, ['the_schema' => 'sch']));
		$this->assertSame(['title' => 'mandaat'], $this->save($which, $store, ['register' => 'reg']));
		$this->assertSame([], $this->saves);
	}//end testAnUnconfiguredSchemaSavesNothing()

	/**
	 * A save that throws answers the payload and logs the service's own message.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testAFailedSaveIsLoggedAndAnswersThePayload(string $which, string $message, string $contextKey): void {
		$this->assertSame(['title' => 'mandaat'], $this->save($which, $this->store(new RuntimeException('store down'))));
		$this->assertSame([[$message, [$contextKey => 'the_schema', 'error' => 'store down']]], $this->errors);
	}//end testAFailedSaveIsLoggedAndAnswersThePayload()
}//end class
