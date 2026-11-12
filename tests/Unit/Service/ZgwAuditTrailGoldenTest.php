<?php

/**
 * ZgwService audit trail golden tests.
 *
 * Pins what the audit trail list and show answer: entries mapped from
 * OpenRegister's logs (arrays and serialisable objects, every action), the
 * synthetic entry when there is none, a failing store logged and answered
 * with what was read, and an entry not found.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for ZgwService::handleAudittrailIndex() and handleAudittrailShow().
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwAuditTrailGoldenTest extends TestCase {

	private const URL = 'https://zgw.test/index.php/apps/dossiq/api/zgw/zaken/v1/zaken/z-1';

	/**
	 * The warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The service over a store answering these logs, or failing.
	 *
	 * @param array<int, mixed>|null $logs The logs, null for no store, a Throwable to fail.
	 *
	 * @return ZgwService
	 */
	private function service(mixed $logs): ZgwService {
		$store = null;
		if ($logs !== null) {
			$store = new class ($logs) {
				/**
				 * @param mixed $logs The logs, or the failure.
				 */
				public function __construct(private mixed $logs) {
				}

				/**
				 * @param string $uuid The object.
				 * @param mixed  ...$rest The other arguments.
				 *
				 * @return array<int, mixed>
				 */
				public function getLogs(string $uuid, ...$rest): array {
					if ($this->logs instanceof \Throwable) {
						throw $this->logs;
					}

					return $uuid === 'z-1' && $rest === [[], false, false] ? $this->logs : [];
				}
			};
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		$service = (new \ReflectionClass(ZgwService::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			static function () use ($service, $store, $logger): void {
				$service->logger = $logger;
				$service->objectService = $store;
			},
			null,
			ZgwService::class
		)();

		return $service;
	}//end service()

	/**
	 * A request on host zgw.test over https.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('zgw.test');
		$request->method('getServerProtocol')->willReturn('https');

		return $request;
	}//end request()

	/**
	 * A serialisable log object.
	 *
	 * @param array<string, mixed> $data The log.
	 *
	 * @return \JsonSerializable
	 */
	private static function logObject(array $data): \JsonSerializable {
		return new class ($data) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $data The log.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end logObject()

	/**
	 * The logs of z-1, one per kind of action.
	 *
	 * @return array<int, mixed>
	 */
	private static function logs(): array {
		return [
			['uuid' => 'a-1', 'action' => 'save', 'user' => 'u1', 'userName' => 'Ann', 'created' => '2026-01-01T10:00:00+00:00'],
			self::logObject(['uuid' => 'a-2', 'action' => 'patch', 'created' => '2026-01-02T10:00:00+00:00']),
			['uuid' => 'a-3', 'action' => 'referential_integrity.cascade_delete', 'created' => 'c3'],
			['uuid' => 'a-4', 'action' => 'unlock', 'created' => 'c4'],
			['uuid' => 'a-5', 'action' => 'archive', 'created' => 'c5'],
			['uuid' => 'a-6', 'action' => 'list', 'created' => 'c6'],
			['created' => 'c7'],
		];
	}//end logs()

	/**
	 * The fields every entry of z-1 carries about the resource.
	 *
	 * @return array<string, string>
	 */
	private static function resourceFields(): array {
		return ['hoofdObject' => self::URL, 'resource' => 'zaken', 'resourceUrl' => self::URL, 'resourceWeergave' => 'zaken'];
	}//end resourceFields()

	/**
	 * The list maps every log, in order.
	 *
	 * @return void
	 */
	public function testTheListMapsEveryLog(): void {
		$data = $this->service(self::logs())->handleAudittrailIndex($this->request(), 'zaken', 'zaken', 'z-1')->getData();

		$expected = [
			['a-1', 'u1', 'Ann', 'create', 'Object aangemaakt', '2026-01-01T10:00:00+00:00'],
			['a-2', 'procest', 'Dossiq', 'partial_update', 'Object deels bijgewerkt', '2026-01-02T10:00:00+00:00'],
			['a-3', 'procest', 'Dossiq', 'destroy', 'Object verwijderd', 'c3'],
			['a-4', 'procest', 'Dossiq', 'destroy', 'Object verwijderd', 'c4'],
			['a-5', 'procest', 'Dossiq', 'archive', 'Archive', 'c5'],
			['a-6', 'procest', 'Dossiq', 'list', 'Objecten opgevraagd', 'c6'],
			['', 'procest', 'Dossiq', 'create', 'Object aangemaakt', 'c7'],
		];
		$this->assertCount(count($expected), $data);
		foreach ($expected as $index => [$uuid, $user, $userName, $action, $display, $created]) {
			$this->assertSame(
				['uuid' => $uuid, 'bron' => 'procest', 'applicatieId' => $user, 'applicatieWeergave' => $userName, 'action' => $action, 'actieWeergave' => $display, 'result' => 200]
				+ self::resourceFields() + ['aanmaakdatum' => $created],
				$data[$index]
			);
		}
	}//end testTheListMapsEveryLog()

	/**
	 * Without logs, without a store and on a failing store the list holds one synthetic entry.
	 *
	 * @return void
	 */
	public function testAnEmptyListHoldsTheSyntheticEntry(): void {
		foreach ([[], null, new RuntimeException('down')] as $logs) {
			$data = $this->service($logs)->handleAudittrailIndex($this->request(), 'zaken', 'zaken', 'z-1')->getData();

			$this->assertCount(1, $data);
			$entry = $data[0];
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $entry['aanmaakdatum']);
			unset($entry['aanmaakdatum']);
			$this->assertSame(
				['uuid' => 'z-1-audit-1', 'bron' => 'procest', 'applicatieId' => 'procest', 'applicatieWeergave' => 'Dossiq', 'action' => 'create', 'actieWeergave' => 'Object aangemaakt', 'result' => 200] + self::resourceFields(),
				$entry
			);
			$this->assertSame(array_keys(['uuid' => 1, 'bron' => 1, 'applicatieId' => 1, 'applicatieWeergave' => 1, 'action' => 1, 'actieWeergave' => 1, 'result' => 1, 'hoofdObject' => 1, 'resource' => 1, 'resourceUrl' => 1, 'resourceWeergave' => 1, 'aanmaakdatum' => 1]), array_keys($data[0]));
		}

		$this->assertSame(['Failed to fetch audit trail for z-1: down'], $this->warnings);
	}//end testAnEmptyListHoldsTheSyntheticEntry()

	/**
	 * A log that fails to serialise mid-list keeps the entries before it.
	 *
	 * @return void
	 */
	public function testAFailureMidListKeepsWhatWasRead(): void {
		$broken = new class () implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('broken log');
			}
		};

		$data = $this->service([['uuid' => 'a-1', 'action' => 'update', 'created' => 'c1'], $broken])->handleAudittrailIndex($this->request(), 'zaken', 'zaken', 'z-1')->getData();

		$this->assertSame(['a-1'], array_column($data, 'uuid'));
		$this->assertSame('Object bijgewerkt', $data[0]['actieWeergave']);
		$this->assertSame(['Failed to fetch audit trail for z-1: broken log'], $this->warnings);
	}//end testAFailureMidListKeepsWhatWasRead()

	/**
	 * Show answers the matching log, from an array or an object.
	 *
	 * @return void
	 */
	public function testShowAnswersTheMatchingLog(): void {
		$service = $this->service(self::logs());

		$this->assertSame('partial_update', $service->handleAudittrailShow($this->request(), 'zaken', 'zaken', 'z-1', 'a-2')->getData()['action']);
		$this->assertSame(
			['uuid' => 'a-3', 'bron' => 'procest', 'applicatieId' => 'procest', 'applicatieWeergave' => 'Dossiq', 'action' => 'destroy', 'actieWeergave' => 'Object verwijderd', 'result' => 200] + self::resourceFields() + ['aanmaakdatum' => 'c3'],
			$service->handleAudittrailShow($this->request(), 'zaken', 'zaken', 'z-1', 'a-3')->getData()
		);
		$this->assertSame([], $this->warnings);
	}//end testShowAnswersTheMatchingLog()

	/**
	 * Show answers a synthetic entry under the asked uuid when it finds none.
	 *
	 * @return void
	 */
	public function testShowFallsBackToASyntheticEntry(): void {
		foreach ([self::logs(), null, new RuntimeException('down')] as $logs) {
			$data = $this->service($logs)->handleAudittrailShow($this->request(), 'zaken', 'zaken', 'z-1', 'a-9')->getData();

			unset($data['aanmaakdatum']);
			$this->assertSame(
				['uuid' => 'a-9', 'bron' => 'procest', 'applicatieId' => 'procest', 'applicatieWeergave' => 'Dossiq', 'action' => 'create', 'actieWeergave' => 'Object aangemaakt', 'result' => 200] + self::resourceFields(),
				$data
			);
		}

		$this->assertSame(['Failed to fetch audit trail entry a-9: down'], $this->warnings);
	}//end testShowFallsBackToASyntheticEntry()
}//end class
