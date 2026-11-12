<?php

/**
 * AanvullingsverzoekService against a store: writing a request, reading a
 * case's requests, and remembering the portal task a request raised.
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\ResidentQuestionTask;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

class AanvullingsverzoekServiceStoreTest extends TestCase {

	/**
	 * A store that records saves and patches and answers searches.
	 *
	 * @param array<int, array<string, mixed>> $rows   What a search answers.
	 * @param bool                             $broken Whether every call throws.
	 *
	 * @return object The fake.
	 */
	private function store(array $rows = [], bool $broken = false): object {
		return new class($rows, $broken) {
			/**
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<int, array<string, mixed>> $rows   What a search answers.
			 * @param bool                             $broken Whether every call throws.
			 */
			public function __construct(private array $rows, private bool $broken) {
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register, mixed $schema, ?string $uuid = null): array {
				$this->calls[] = ['save', $object, $register, $schema];
				if ($this->broken === true) {
					throw new RuntimeException('store down');
				}

				return array_merge($object, ['id' => 'avr-new']);
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
				$this->calls[] = ['patch', $objectId, $data, $register, $schema];
				if ($this->broken === true) {
					throw new RuntimeException('store down');
				}

				return array_merge(['id' => $objectId, 'portalTask' => 'task-1'], $data);
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = ['search', $register, $schema, $filters];
				if ($this->broken === true) {
					throw new RuntimeException('store down');
				}

				return $this->rows;
			}
		};
	}//end store()

	/**
	 * The service over a store, with a logger that keeps what it was told.
	 *
	 * @param object                    $store      The fake store.
	 * @param array<int, array<mixed>>  $logged     Receives [level, message, context].
	 * @param ResidentQuestionTask|null $portalTask The portal task, or null.
	 *
	 * @return AanvullingsverzoekService
	 */
	private function service(object $store, array &$logged, ?ResidentQuestionTask $portalTask = null): AanvullingsverzoekService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturn('dossiq');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logged, $level): void {
					$logged[] = [$level, $message, $context];
				}
			);
		}

		return new AanvullingsverzoekService(
			act: $this->createMock(originalClassName: InformationRequestService::class),
			settingsService: $settings,
			logger: $logger,
			portalTask: $portalTask,
		);
	}//end service()

	/**
	 * A new request is saved; a changed one is patched by its trimmed id.
	 *
	 * @return void
	 */
	public function testANewRequestIsSavedAndAChangedOneIsPatched(): void {
		$logged = [];
		$store = $this->store();
		$service = $this->service(store: $store, logged: $logged);

		self::assertSame(['case' => 'case-1', 'id' => 'avr-new'], $service->write(request: ['case' => 'case-1']));
		self::assertSame(['id' => 'avr-1', 'portalTask' => 'task-1', 'hersteltermijn' => '2026-11-01'], $service->write(request: ['hersteltermijn' => '2026-11-01'], id: ' avr-1 '));
		self::assertSame(
			[
				['save', ['case' => 'case-1'], 'dossiq', 'aanvullingsverzoek'],
				['patch', 'avr-1', ['hersteltermijn' => '2026-11-01'], 'dossiq', 'aanvullingsverzoek'],
			],
			$store->calls
		);
		self::assertSame([], $logged);
	}//end testANewRequestIsSavedAndAChangedOneIsPatched()

	/**
	 * A write the store refuses, new or changed, is logged and refused, as is a portal task that cannot be closed.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndRefused(): void {
		$closing = $this->createMock(ResidentQuestionTask::class);
		$closing->method('close')->willThrowException(new RuntimeException('task store down'));
		$cases = [
			[$this->store(broken: true), null, ['case' => 'case-1'], ''],
			[$this->store(broken: true), null, ['note' => 'x'], 'avr-1'],
			[$this->store(), $closing, ['state' => 'answered'], 'avr-1'],
		];
		foreach ($cases as [$store, $portalTask, $request, $id]) {
			$logged = [];
			try {
				$this->service(store: $store, logged: $logged, portalTask: $portalTask)->write(request: $request, id: $id);
				self::fail('A failed write must be refused.');
			} catch (RefusedException $e) {
				self::assertSame('aanvullingsverzoek-not-written', $e->getRule());
				self::assertSame('The request could not be written down.', $e->getSentence());
			}

			self::assertSame('error', $logged[0][0]);
			self::assertSame('Dossiq: an aanvullingsverzoek could not be written', $logged[0][1]);
			self::assertSame('dossiq', $logged[0][2]['app']);
		}
	}//end testAFailedWriteIsLoggedAndRefused()

	/**
	 * A case's requests are read by case, oldest first; a blank case reads nothing.
	 *
	 * @return void
	 */
	public function testACasesRequestsAreReadOldestFirst(): void {
		$logged = [];
		$store = $this->store(rows: [
			['id' => 'b', 'requestedAt' => '2026-10-02T09:00:00+00:00'],
			['id' => 'a', 'requestedAt' => '2026-10-01T09:00:00+00:00'],
			['id' => 'c'],
		]);
		$service = $this->service(store: $store, logged: $logged);

		self::assertSame(['c', 'a', 'b'], array_column($service->forCase(caseId: ' case-1 '), 'id'));
		self::assertSame([['search', 'dossiq', 'aanvullingsverzoek', ['case' => 'case-1', '_limit' => 100]]], $store->calls);
		self::assertSame([], $service->forCase(caseId: '  '));
		self::assertCount(1, $store->calls);
	}//end testACasesRequestsAreReadOldestFirst()

	/**
	 * Requests that cannot be read are logged and refused.
	 *
	 * @return void
	 */
	public function testUnreadableRequestsAreLoggedAndRefused(): void {
		$logged = [];
		try {
			$this->service(store: $this->store(broken: true), logged: $logged)->forCase(caseId: 'case-1');
			self::fail('An unreadable store must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('aanvullingsverzoek-unreadable', $e->getRule());
			self::assertSame('The requests on this case could not be read, so nothing was changed.', $e->getSentence());
		}

		self::assertSame(
			[['warning', 'Dossiq: the aanvullingsverzoeken on a case could not be read', ['app' => 'dossiq', 'case' => 'case-1', 'error' => 'store down']]],
			$logged
		);
	}//end testUnreadableRequestsAreLoggedAndRefused()
}//end class
