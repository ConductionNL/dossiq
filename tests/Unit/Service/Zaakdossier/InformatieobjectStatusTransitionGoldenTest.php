<?php

/**
 * Characterisation tests for InformatieobjectStatusLifecycle::transition: every
 * refusal and its message, what each allowed move answers, and its log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\Dossiq\Tests\Unit\Fixtures\PatchingObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class InformatieobjectStatusTransitionGoldenTest extends TestCase {

	/**
	 * Every line logged.
	 *
	 * @var array<int, array>
	 */
	private array $lines = [];

	private PatchingObjectService $store;

	private const DOCUMENT = [
		'title' => 'T',
		'fileName' => 'a.pdf',
		'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
		'informatieobjecttype' => 'iot-1',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->store = PatchingObjectService::forShippedSchema('informatieobject');
	}//end setUp()

	private function lifecycle(): InformatieobjectStatusLifecycle {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
			][$key] ?? $default
		);

		$sink = &$this->lines;
		$logger = new class($sink) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = [(string)$level, (string)$message, $context];
			}
		};

		return new InformatieobjectStatusLifecycle($settings, $logger);
	}//end lifecycle()

	private function refusal(\Closure $call): array {
		try {
			$call();
		} catch (InvalidArgumentException | RuntimeException $e) {
			return [get_class($e), $e->getMessage()];
		}

		self::fail('The transition was allowed.');

		return [];
	}//end refusal()

	public function testAMoveToFinalAnswersItsLockStampAndLogs(): void {
		$this->store->stored['inf-1'] = self::DOCUMENT;
		$result = $this->lifecycle()->transition('inf-1', 'final');

		self::assertSame(['id', 'status', 'lockedOn'], array_keys($result));
		self::assertSame('final', $result['status']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $result['lockedOn']);
		self::assertSame($result['lockedOn'], $this->store->stored['inf-1']['lockedOn']);
		self::assertSame(
			[['info', 'Dossiq dossier: informatieobject inf-1 transitioned draft -> final', ['app' => 'dossiq']]],
			$this->lines
		);
	}//end testAMoveToFinalAnswersItsLockStampAndLogs()

	public function testAMoveToArchivedAnswersNoLockStamp(): void {
		$this->store->stored['inf-1'] = self::DOCUMENT + ['status' => 'final', 'lockedOn' => '2026-01-01T00:00:00'];

		self::assertSame(['id' => 'inf-1', 'status' => 'archived'], $this->lifecycle()->transition('inf-1', 'archived'));
	}//end testAMoveToArchivedAnswersNoLockStamp()

	public function testEveryRefusalAndItsMessage(): void {
		$this->store->stored['inf-1'] = ['status' => 'final'];
		$this->store->stored['inf-2'] = ['status' => 'archived'];

		self::assertSame([InvalidArgumentException::class, 'Invalid status: deleted'], $this->refusal(fn () => $this->lifecycle()->transition('inf-1', 'deleted')));
		self::assertSame([RuntimeException::class, 'Informatieobject not found: gone'], $this->refusal(fn () => $this->lifecycle()->transition('gone', 'final')));
		self::assertSame([InvalidArgumentException::class, 'Invalid status transition from final to final'], $this->refusal(fn () => $this->lifecycle()->transition('inf-1', 'final')));
		self::assertSame([InvalidArgumentException::class, 'Invalid status transition from final to draft'], $this->refusal(fn () => $this->lifecycle()->transition('inf-1', 'draft')));
		self::assertSame([InvalidArgumentException::class, 'Invalid status transition from archived to final'], $this->refusal(fn () => $this->lifecycle()->transition('inf-2', 'final')));
		self::assertSame('final', $this->store->stored['inf-1']['status']);
		self::assertSame([], $this->lines);
	}//end testEveryRefusalAndItsMessage()
}//end class
