<?php

/**
 * The one door onto OpenRegister's data subject rights, tested for the two
 * things it is for: composing the platform's calls in the right order, and
 * carrying the platform's refusal across without flattening it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Gdpr;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Gdpr\PlatformDataSubjectRights;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * What dossiq asks the platform, and what it does with the answer.
 */
class PlatformDataSubjectRightsTest extends TestCase {

	/**
	 * A container that answers with the doubles it is given, and nothing else.
	 *
	 * @param array<string, object> $services Fully qualified name to double.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => isset($services[$id])
		);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $services[$id];
			}
		);

		return $container;
	}

	/**
	 * A double of the platform's preview computation.
	 *
	 * @param array<string, mixed> $report What the platform reports.
	 *
	 * @return object
	 */
	private function previewService(array $report): object {
		return new class($report) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $report The report to answer with.
			 */
			public function __construct(private readonly array $report) {
			}

			/**
			 * @param string      $subjectId The subject.
			 * @param string|null $type      The kind.
			 * @param string      $eraseMode The mode.
			 *
			 * @return array<string, mixed>
			 */
			public function preview(string $subjectId, ?string $type, string $eraseMode): array {
				$this->calls[] = ['subjectId' => $subjectId, 'type' => $type, 'eraseMode' => $eraseMode];

				return $this->report;
			}
		};
	}

	/**
	 * A double of the platform's preview store.
	 *
	 * @param array<string, mixed> $row The row it hands back.
	 *
	 * @return object
	 */
	private function store(array $row): object {
		return new class($row) {
			/** @var array<int, string> */
			public array $acts = [];

			/** @var array<string, mixed>|null */
			public ?array $recorded = null;

			/** @var string */
			public string $requestId = '';

			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private readonly array $row) {
			}

			/**
			 * @param array<string, mixed> $preview   The preview.
			 * @param string|null          $requestId The request.
			 *
			 * @return object
			 */
			public function record(array $preview, ?string $requestId = null): object {
				$this->acts[] = 'record';
				$this->recorded = $preview;
				$this->requestId = (string)$requestId;

				return $this->entity();
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function requireRunnable(string $uuid): object {
				$this->acts[] = 'requireRunnable:' . $uuid;

				return $this->entity();
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function approve(string $uuid): object {
				$this->acts[] = 'approve:' . $uuid;

				return $this->entity();
			}

			/**
			 * @param object               $preview The preview.
			 * @param array<string, mixed> $outcome The outcome.
			 *
			 * @return object
			 */
			public function consume(object $preview, array $outcome): object {
				$this->acts[] = 'consume';

				return $preview;
			}

			/**
			 * @return object
			 */
			private function entity(): object {
				$row = $this->row;

				return new class($row) {
					/**
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private readonly array $row) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}
				};
			}
		};
	}

	/**
	 * The preview is computed and then recorded, and the recorded row comes back.
	 *
	 * @return void
	 */
	public function testThePreviewIsComputedThenRecorded(): void {
		$report = ['counts' => ['erasable' => ['objects' => 8]], 'protected' => [['name' => 'hold']]];
		$preview = $this->previewService(report: $report);
		$store = $this->store(row: ['uuid' => 'p1', 'digest' => 'abc', 'report' => $report]);

		$rights = new PlatformDataSubjectRights(
			container: $this->container(
				[
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService' => $preview,
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore' => $store,
				]
			),
			logger: new NullLogger(),
		);

		$answer = $rights->previewErasure(subject: 'a@b.nl', type: 'email', eraseMode: 'whole-object', requestId: 'case1');

		self::assertSame('p1', $answer['uuid']);
		self::assertSame($report, $store->recorded);
		self::assertSame('case1', $store->requestId);
		self::assertSame(
			[['subjectId' => 'a@b.nl', 'type' => 'email', 'eraseMode' => 'whole-object']],
			$preview->calls
		);
	}//end testThePreviewIsComputedThenRecorded()

	/**
	 * A mode the platform does not know becomes the one that keeps the object.
	 *
	 * @return void
	 */
	public function testAnUnknownModeFallsBackToPseudonymise(): void {
		$preview = $this->previewService(report: []);
		$store = $this->store(row: ['uuid' => 'p1']);

		$rights = new PlatformDataSubjectRights(
			container: $this->container(
				[
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService' => $preview,
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore' => $store,
				]
			),
			logger: new NullLogger(),
		);

		$rights->previewErasure(subject: 'a@b.nl', type: null, eraseMode: 'obliterate', requestId: null);

		self::assertSame('pseudonymise', $preview->calls[0]['eraseMode']);
	}//end testAnUnknownModeFallsBackToPseudonymise()

	/**
	 * The run requires a runnable preview, runs it, and marks it spent.
	 *
	 * @return void
	 */
	public function testTheRunRequiresApprovalThenConsumesThePreview(): void {
		$store = $this->store(row: ['uuid' => 'p1']);
		$runner = new class {
			/**
			 * @param object $record The preview.
			 *
			 * @return array<string, mixed>
			 */
			public function run(object $record): array {
				return ['destroyed' => ['o1'], 'withheld' => [], 'complete' => true];
			}
		};

		$rights = new PlatformDataSubjectRights(
			container: $this->container(
				[
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore' => $store,
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner' => $runner,
				]
			),
			logger: new NullLogger(),
		);

		$outcome = $rights->runErasure(previewId: 'p1');

		self::assertTrue($outcome['complete']);
		self::assertSame(['requireRunnable:p1', 'consume'], $store->acts);
	}//end testTheRunRequiresApprovalThenConsumesThePreview()

	/**
	 * The platform's own rule and status survive the translation.
	 *
	 * @return void
	 */
	public function testThePlatformsRuleAndStatusAreCarriedAcross(): void {
		$store = new class {
			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function requireRunnable(string $uuid): object {
				throw new ErasureRefusedException(
					rule: 'erasure-preview-stale',
					reason: 'The world moved since this preview was taken.',
					statusCode: 409,
				);
			}
		};

		$rights = new PlatformDataSubjectRights(
			container: $this->container(
				[
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore' => $store,
					'OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner' => new class {
					},
				]
			),
			logger: new NullLogger(),
		);

		try {
			$rights->runErasure(previewId: 'p1');
			self::fail('the refusal did not reach the caller');
		} catch (RefusedException $e) {
			self::assertSame('erasure-preview-stale', $e->getRule());
			self::assertSame(409, $e->getStatus());
			self::assertSame('The world moved since this preview was taken.', $e->getSentence());
		}
	}//end testThePlatformsRuleAndStatusAreCarriedAcross()

	/**
	 * Something the platform did not name becomes indeterminate, not a refusal.
	 *
	 * @return void
	 */
	public function testAnUnnamedFailureIsIndeterminate(): void {
		$store = new class {
			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function load(string $uuid): object {
				throw new RuntimeException('the database went away');
			}
		};

		$rights = new PlatformDataSubjectRights(
			container: $this->container(
				['OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore' => $store]
			),
			logger: new NullLogger(),
		);

		try {
			$rights->preview(previewId: 'p1');
			self::fail('the failure did not reach the caller');
		} catch (RefusedException $e) {
			self::assertSame('data-subject-rights-failed', $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testAnUnnamedFailureIsIndeterminate()

	/**
	 * An OpenRegister without the capability says so, rather than 500ing.
	 *
	 * @return void
	 */
	public function testAnOlderOpenRegisterSaysSo(): void {
		$rights = new PlatformDataSubjectRights(
			container: $this->container([]),
			logger: new NullLogger(),
		);

		self::assertFalse($rights->isAvailable());

		try {
			$rights->preview(previewId: 'p1');
			self::fail('the absence did not reach the caller');
		} catch (RefusedException $e) {
			self::assertSame('data-subject-rights-unavailable', $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testAnOlderOpenRegisterSaysSo()

	/**
	 * An export that is gone reads as an empty answer, not as a failure.
	 *
	 * @return void
	 */
	public function testAnExportThatIsGoneReadsEmpty(): void {
		$service = new class {
			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object|null
			 */
			public function load(string $uuid): ?object {
				return null;
			}
		};

		$rights = new PlatformDataSubjectRights(
			container: $this->container(
				['OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService' => $service]
			),
			logger: new NullLogger(),
		);

		self::assertSame([], $rights->export(exportId: 'e1'));
	}//end testAnExportThatIsGoneReadsEmpty()
}//end class
