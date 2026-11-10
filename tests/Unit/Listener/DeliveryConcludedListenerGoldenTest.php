<?php

/**
 * Golden test for projecting a concluded delivery onto its case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DeliveryConcludedListener;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\Integriq\Event\DeliveryConcludedEvent;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every find, save and log line of a concluded delivery.
 *
 * @covers \OCA\Dossiq\Listener\DeliveryConcludedListener
 * @covers \OCA\Dossiq\Listener\Support\ConcludedDelivery
 */
class DeliveryConcludedListenerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call and log line, in order.
	 *
	 * @var \ArrayObject<int, mixed>
	 */
	private \ArrayObject $trace;

	/**
	 * A case with three publications.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseRow(): array {
		return [
			'id' => 'c1',
			'title' => 'Zaak',
			'publications' => [
				'not a publication',
				['channel' => 'krant', 'delivery' => ['status' => 'requested', 'correlationId' => 'corr-0']],
				['channel' => 'gemeenteblad', 'notes' => null, 'delivery' => ['status' => 'requested', 'correlationId' => 'corr-1', 'eventId' => 'evt-1', 'attempts' => 1]],
				['channel' => 'site', 'delivery' => ['status' => 'requested', 'correlationId' => 'corr-1']],
				['channel' => 'leeg'],
			],
		];
	}//end caseRow()

	/**
	 * Hand one event to the listener and answer what it did.
	 *
	 * @param array<string, mixed> $event Event field changes.
	 * @param array<string, mixed> $world `case` (row, object, null or 'throw'), `service`, `saveThrows`.
	 *
	 * @return array<int, mixed>
	 */
	private function observe(array $event = [], array $world = []): array {
		$this->trace = new \ArrayObject();
		$fields = array_merge(
			[
				'sourceApp' => 'dossiq',
				'correlationId' => 'corr-1',
				'subjectId' => 'c1',
				'channel' => 'gemeenteblad',
				'status' => 'delivered',
				'eventId' => 'evt-9',
				'messageId' => 'msg-9',
				'attempts' => 3,
				'error' => null,
				'concludedAt' => '2026-09-02T12:00:00+00:00',
			],
			$event
		);
		$case = array_key_exists('case', $world) === true ? $world['case'] : self::caseRow();
		$store = new class($case, ($world['saveThrows'] ?? false), $this->trace) {
			/**
			 * @param mixed                    $case       What find answers.
			 * @param bool                     $saveThrows Whether a save throws.
			 * @param \ArrayObject<int, mixed> $trace      The trace.
			 */
			public function __construct(private mixed $case, private bool $saveThrows, private \ArrayObject $trace) {
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				$this->trace->append(['find', $id, $register, $schema]);
				if ($this->case === 'throw') {
					throw new RuntimeException('store down');
				}

				return $this->case;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$this->trace->append(['save', $register, $schema, $object]);
				if ($this->saveThrows === true) {
					throw new RuntimeException('save refused');
				}

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($world['service'] ?? true) === true ? $store : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => (['register' => 'reg-1', 'case_schema' => 'case-s'][$key] ?? ''));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace->append(['log', $level, $message, $context]);
				}
			);
		}

		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		$listener = new DeliveryConcludedListener(settingsService: $settings, logger: $logger, serviceAccount: $account);
		$listener->handle(new DeliveryConcludedEvent(...$fields));

		return $this->trace->getArrayCopy();
	}//end observe()

	/**
	 * Every path of the projection.
	 *
	 * @return void
	 */
	public function testGoldenProjection(): void {
		$jsonCase = new class(self::caseRow()) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
		$repeated = self::caseRow();
		$repeated['publications'][2]['delivery']['status'] = 'delivered';

		$observed = [
			'delivered' => $this->observe(),
			'abandoned with an error' => $this->observe(event: ['status' => 'Abandoned', 'error' => 'receiver gone', 'attempts' => 7]),
			'not terminal' => $this->observe(event: ['status' => 'failed']),
			'another app' => $this->observe(event: ['sourceApp' => 'pipelinq']),
			'no case id' => $this->observe(event: ['subjectId' => '']),
			'no correlation id' => $this->observe(event: ['correlationId' => '']),
			'no object service' => $this->observe(world: ['service' => false]),
			'case not found' => $this->observe(world: ['case' => null]),
			'case read throws' => $this->observe(world: ['case' => 'throw']),
			'case as an object' => $this->observe(world: ['case' => $jsonCase]),
			'publications not a list' => $this->observe(world: ['case' => ['id' => 'c1', 'publications' => 'none']]),
			'no publications' => $this->observe(world: ['case' => ['id' => 'c1']]),
			'no publication matches' => $this->observe(event: ['correlationId' => 'corr-9']),
			'already projected' => $this->observe(world: ['case' => $repeated]),
			'first match only' => $this->observe(event: ['correlationId' => 'corr-0', 'channel' => 'krant']),
			'save throws' => $this->observe(world: ['saveThrows' => true]),
		];

		$this->assertMatchesGolden('delivery-concluded-projection', $observed);
	}//end testGoldenProjection()
}//end class
