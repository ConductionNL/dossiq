<?php

/**
 * PublicationService golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\PublicationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * Golden test for PublicationService::publish(): the answer or the exception, every
 * settings, store and dispatch call, the dispatched delivery request and the logs.
 *
 * @covers \OCA\Dossiq\Service\PublicationService
 */
class PublicationServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Replace the values that change per run (now, the random correlation id) with markers.
	 *
	 * @param mixed $value The observed value.
	 *
	 * @return mixed The value with markers.
	 */
	private function stable(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->stable(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^[0-9a-f]{32}$/', $value) === 1) {
			return '<correlation-id>';
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $value) === 1) {
			return '<now>';
		}

		return $value;
	}//end stable()

	/**
	 * Run one publish in a world and observe it.
	 *
	 * @param array<string, mixed> $world The payload, the stored case, the settings and the dispatch answer.
	 *
	 * @return array<string, mixed> The answer or exception, the calls in order, the request and the logs.
	 */
	private function observe(array $world): array {
		$calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$store = new class($world, $calls) {
			/**
			 * @param array<string, mixed> $world The world.
			 * @param array<int, mixed>    $calls The call log.
			 */
			public function __construct(private array $world, private array &$calls) {
			}

			/**
			 * Answer the stored case.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed The case.
			 */
			public function find(string $id, mixed $register, mixed $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				$found = ($this->world['case'] ?? null);
				if ($found === 'throw') {
					throw new RuntimeException('store down');
				}

				return $found;
			}

			/**
			 * Keep the saved case.
			 *
			 * @param array<string, mixed> $object   The case.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function saveObject(array $object, mixed $register, mixed $schema): array {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				return $object;
			}
		};

		$config = ($world['config'] ?? []) + ['register' => 'dossiq', 'case_schema' => 'case'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use (&$calls, $world, $store): ?object {
				$calls[] = ['getObjectService'];
				if (($world['noStore'] ?? false) === true) {
					return null;
				}

				return $store;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use (&$calls, $config): mixed {
				$calls[] = ['getConfigValue', $key];
				return $config[$key];
			}
		);

		$request = null;
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$calls, &$request, $world): void {
				$calls[] = ['dispatchTyped', get_class($event)];
				$request = $event;
				$answer = ($world['answer'] ?? 'requested');
				if ($answer === 'throw') {
					throw new RuntimeException('bus down');
				}

				if ($answer === 'ignored') {
					return;
				}

				$event->setResultId(resultId: 'evt-9');
				$event->setHandled(handled: true);
				if ($answer === 'requested') {
					$event->setMatchedSubscriptions(matchedSubscriptions: 3);
				}
			}
		);

		$service = new PublicationService(settingsService: $settings, eventDispatcher: $dispatcher, logger: $logger);
		try {
			$observed = ['answer' => $service->publish(caseId: ($world['caseId'] ?? 'case-1'), payload: ($world['payload'] ?? []))];
		} catch (Throwable $e) {
			$observed = ['exception' => [get_class($e), $e->getMessage()]];
		}

		$observed['calls'] = $calls;
		if ($request instanceof DeliveryRequestedEvent) {
			$observed['request'] = [
				'sourceApp' => $request->getSourceApp(),
				'subjectRegister' => $request->getSubjectRegister(),
				'subjectSchema' => $request->getSubjectSchema(),
				'subjectId' => $request->getSubjectId(),
				'subjectLabel' => $request->getSubjectLabel(),
				'deliveryKind' => $request->getDeliveryKind(),
				'channel' => $request->getChannel(),
				'payload' => $request->getPayload(),
				'correlationId' => $request->getCorrelationId(),
				'externalReference' => $request->getExternalReference(),
				'userId' => $request->getUserId(),
			];
		}

		$observed['logs'] = $logger->lines;

		return $this->stable(value: $observed);
	}//end observe()

	/**
	 * Every publish path, recorded once and compared on each run.
	 *
	 * @return void
	 */
	public function testGoldenPublish(): void {
		$case = [
			'id' => 'case-1',
			'title' => 'Kapvergunning Dorpsstraat',
			'identifier' => 'Z-2026-0042',
			'besluitDocument' => 'doc-7',
		];
		$at = ['publishedAt' => '2026-05-01 10:00'];
		$serialisable = new class($case) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $case The case.
			 */
			public function __construct(private array $case) {
			}

			/**
			 * The case as an array.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function jsonSerialize(): array {
				return $this->case + ['serialised' => true];
			}
		};
		$worlds = [
			'requested on the default channel' => ['case' => $case, 'payload' => $at],
			'requested with notes' => ['case' => $case, 'payload' => ['channel' => 'gemeenteblad', 'notes' => 42] + $at],
			'publishedAt defaults to now' => ['case' => $case, 'payload' => ['channel' => 'pdc']],
			'unrouted' => ['case' => $case, 'answer' => 'unrouted', 'payload' => $at],
			'not handled' => ['case' => $case, 'answer' => 'ignored', 'payload' => $at],
			'dispatch throws' => ['case' => $case, 'answer' => 'throw', 'payload' => ['channel' => 'open_raadsinformatie'] + $at],
			'case without title or identifier' => ['case' => ['id' => 'case-1'], 'payload' => $at],
			'same channel is replaced' => [
				'case' => $case + ['publications' => [
					['channel' => 'pdc', 'publishedAt' => 'old'],
					['channel' => 'website', 'publishedAt' => 'old', 'notes' => 'x', 'delivery' => ['status' => 'requested']],
					['channel' => 'website', 'publishedAt' => 'second'],
				]],
				'payload' => $at,
			],
			'publications as JSON text' => [
				'case' => $case + ['publications' => '[{"channel":"website","publishedAt":"old"},"junk",{"channel":"pdc"}]'],
				'payload' => $at,
			],
			'publications as unreadable JSON text' => ['case' => $case + ['publications' => '{oops'], 'payload' => $at],
			'publications as a scalar' => ['case' => $case + ['publications' => 7], 'payload' => $at],
			'publications with a non-array entry' => ['case' => $case + ['publications' => ['x', ['channel' => 'pdc']]], 'payload' => $at],
			'case as a serialisable object' => ['case' => $serialisable, 'payload' => $at],
			'case as a plain object' => ['case' => (object)$case, 'payload' => $at],
			'empty register and schema' => ['case' => $case, 'config' => ['register' => '', 'case_schema' => ''], 'payload' => $at],
			'case missing' => ['case' => null, 'payload' => $at],
			'case read throws' => ['case' => 'throw', 'caseId' => 'case-9', 'payload' => $at],
			'no store' => ['noStore' => true, 'payload' => $at],
			'invalid channel' => ['case' => $case, 'payload' => ['channel' => 'fax'] + $at],
			'invalid channel before the store' => ['noStore' => true, 'payload' => ['channel' => 'Website'] + $at],
		];

		foreach ($worlds as $name => $world) {
			$this->assertMatchesGolden(name: 'publication-publish/' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), value: [$name => $this->observe(world: $world)]);
		}
	}//end testGoldenPublish()
}//end class
