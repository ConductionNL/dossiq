<?php

/**
 * PortalWooAnswer outcomes.
 *
 * Pins every answer PortalWooAnswer gives a resident's answer to a Woo
 * clarification request, allowed and refused: the 400 on an empty or too
 * long answer, the 503 when OpenRegister or its configuration is missing or
 * the request refuses the write, the 404 for every request the resident may
 * not answer, and the 200 with the fields told to the handler.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Dossiq\Portal\PortalWooAnswer;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Allowed and refused answers to a clarification request.
 *
 * @covers \OCA\Dossiq\Portal\PortalWooAnswer
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class PortalWooAnswerOutcomesTest extends TestCase {
	private const ANNA = 'subject-anna';

	/**
	 * Stored rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Configuration values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg', 'case_schema' => 'case'];

	/**
	 * Whether OpenRegister is there.
	 *
	 * @var bool
	 */
	private bool $hasObjectService = true;

	/**
	 * The open request openFor answers.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $open = ['id' => 'req-1'];

	/**
	 * Every find as [schema, id].
	 *
	 * @var array<int, array{0: mixed, 1: string}>
	 */
	private array $finds = [];

	/**
	 * Thrown by find when set.
	 *
	 * @var RuntimeException|null
	 */
	private ?RuntimeException $findFailure = null;

	/**
	 * Whether the reads ran as the system.
	 *
	 * @var bool
	 */
	private bool $asSystem = false;

	/**
	 * The request service.
	 *
	 * @var AanvullingsverzoekService&MockObject
	 */
	private AanvullingsverzoekService&MockObject $requests;

	/**
	 * The resolution service.
	 *
	 * @var AanvullingsverzoekResolutionService&MockObject
	 */
	private AanvullingsverzoekResolutionService&MockObject $resolution;

	/**
	 * Tells the handler.
	 *
	 * @var ApplicantPortalActs&MockObject
	 */
	private ApplicantPortalActs&MockObject $acts;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Stores attachments.
	 *
	 * @var DocumentRecordStore&MockObject
	 */
	private DocumentRecordStore&MockObject $documents;

	/**
	 * Seed Anna's case and open request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'case-1' => ['portalSubject' => self::ANNA],
			'req-1' => ['case' => 'case-1', 'portalSubject' => self::ANNA, 'state' => 'open'],
		];
		$this->requests = $this->createMock(AanvullingsverzoekService::class);
		$this->requests->method('openFor')->willReturnCallback(fn (): ?array => $this->open);
		$this->resolution = $this->createMock(AanvullingsverzoekResolutionService::class);
		$this->acts = $this->createMock(ApplicantPortalActs::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->documents = $this->createMock(DocumentRecordStore::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return PortalWooAnswer
	 */
	private function service(): PortalWooAnswer {
		$objects = new class ($this) {
			/**
			 * @param PortalWooAnswerOutcomesTest $test The test holding the rows.
			 */
			public function __construct(private PortalWooAnswerOutcomesTest $test) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				return $this->test->asSystem(operation: $operation);
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return $this->test->find(id: $id, schema: $schema);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn () => ($this->hasObjectService === true ? $objects : null));
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));

		return new PortalWooAnswer(
			settings: $settings,
			requests: $this->requests,
			resolution: $this->resolution,
			acts: $this->acts,
			logger: $this->logger,
			documents: $this->documents,
		);
	}//end service()

	/**
	 * Run an operation as the system.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed
	 */
	public function asSystem(callable $operation): mixed {
		$this->asSystem = true;
		$result = $operation();
		$this->asSystem = false;

		return $result;
	}//end asSystem()

	/**
	 * Find a stored row.
	 *
	 * @param string $id     The id.
	 * @param mixed  $schema The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, mixed $schema): ?array {
		$this->assertTrue($this->asSystem);
		if ($this->findFailure !== null) {
			throw $this->findFailure;
		}

		$this->finds[] = [$schema, $id];

		return ($this->rows[$id] ?? null);
	}//end find()

	/**
	 * Answer as Anna.
	 *
	 * @param string               $answer      The answer.
	 * @param array<int, mixed>    $attachments The attachments.
	 * @param string               $requestId   The request.
	 * @param string               $subjectRef  The resident.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(string $answer = ' Alle speeltuinen ', array $attachments = [], string $requestId = ' req-1 ', string $subjectRef = ' subject-anna '): array {
		return $this->service()->answer(
			subjectRef: $subjectRef,
			requestId: $requestId,
			answer: $answer,
			now: new DateTimeImmutable('2026-10-08T10:00:00+02:00'),
			attachments: $attachments
		);
	}//end answer()

	/**
	 * An answer is recorded, written and told to the handler.
	 *
	 * @return void
	 */
	public function testTheAnswerIsRecordedAndToldToTheHandler(): void {
		$this->resolution->expects($this->once())->method('recordAnswer')->with(
			'case-1',
			[],
			false,
			'portal',
			$this->callback(fn (DateTimeImmutable $when): bool => $when->format('c') === '2026-10-08T10:00:00+02:00')
		);
		$this->requests->expects($this->once())->method('write')->with(
			['applicantAnswer' => 'Alle speeltuinen', 'applicantAnsweredAt' => '2026-10-08T10:00:00+02:00'],
			'req-1'
		);
		$this->acts->expects($this->once())->method('recordWrite')->with('case-1', 'answer', ['applicantAnswer'], '2026-10-08T10:00:00+02:00');

		$this->assertSame(
			['status' => 200, 'body' => ['requestId' => 'req-1', 'state' => 'open', 'attachments' => 0]],
			$this->answer()
		);
		$this->assertSame([[AanvullingsverzoekService::SCHEMA, 'req-1'], ['case', 'case-1']], $this->finds);
	}//end testTheAnswerIsRecordedAndToldToTheHandler()

	/**
	 * Filed attachments are counted and named in the fields; a failing one is logged.
	 *
	 * @return void
	 */
	public function testAttachmentsAreFiledAndCounted(): void {
		$this->documents->expects($this->exactly(2))->method('storeFileOnObject')->willReturnCallback(
			function (string $objectId, string $fileName, string $content): int {
				if ($fileName === 'bad.pdf') {
					throw new RuntimeException('disk full');
				}

				return 1;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: a file sent with a portal answer could not be stored on the case',
			['app' => 'dossiq', 'case' => 'case-1', 'error' => 'disk full']
		);
		$this->acts->expects($this->once())->method('recordWrite')->with('case-1', 'answer', ['applicantAnswer', 'documents'], '2026-10-08T10:00:00+02:00');

		$result = $this->answer(attachments: [['name' => 'ok.pdf', 'content' => 'a'], ['name' => 'bad.pdf', 'content' => 'b']]);

		$this->assertSame(1, $result['body']['attachments']);
	}//end testAttachmentsAreFiledAndCounted()

	/**
	 * An empty or too long answer is a 400, before anything is read.
	 *
	 * @return void
	 */
	public function testAnEmptyOrTooLongAnswerIsRefused(): void {
		$invalid = ['status' => 400, 'body' => ['error' => 'invalid', 'message' => 'Write an answer of at most 4000 characters.']];

		$this->assertSame($invalid, $this->answer(answer: '  '));
		$this->assertSame($invalid, $this->answer(answer: str_repeat('é', 4001)));
		$this->assertSame(200, $this->answer(answer: str_repeat('é', 4000))['status']);
	}//end testAnEmptyOrTooLongAnswerIsRefused()

	/**
	 * Without OpenRegister, a register or a case schema the answer is a 503.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredInstanceIsUnavailable(): void {
		$unavailable = ['status' => 503, 'body' => ['error' => 'unavailable']];
		$this->hasObjectService = false;
		$this->assertSame($unavailable, $this->answer());

		$this->hasObjectService = true;
		$this->config['register'] = '';
		$this->assertSame($unavailable, $this->answer());

		$this->config = ['register' => 'reg', 'case_schema' => ''];
		$this->assertSame($unavailable, $this->answer());
		$this->assertSame([], $this->finds);
	}//end testAnUnconfiguredInstanceIsUnavailable()

	/**
	 * Every request the resident may not answer is a 404, and nothing is written.
	 *
	 * @return void
	 */
	public function testARequestTheResidentMayNotAnswerIsNotFound(): void {
		$notFound = ['status' => 404, 'body' => ['error' => 'not_found']];
		$this->requests->expects($this->never())->method('write');
		$this->resolution->expects($this->never())->method('recordAnswer');

		$this->assertSame($notFound, $this->answer(subjectRef: ' '));
		$this->assertSame($notFound, $this->answer(requestId: ''));
		$this->assertSame($notFound, $this->answer(requestId: 'req-unknown'));
		$this->assertSame($notFound, $this->answer(subjectRef: 'subject-bert'));

		$this->rows['req-1']['state'] = 'answered';
		$this->assertSame($notFound, $this->answer());

		$this->setUp();
		$this->requests->expects($this->never())->method('write');
		$this->rows['case-1']['portalSubject'] = 'subject-bert';
		$this->assertSame($notFound, $this->answer());

		$this->setUp();
		$this->requests->expects($this->never())->method('write');
		$this->open = ['uuid' => 'req-2'];
		$this->assertSame($notFound, $this->answer());

		$this->open = null;
		$this->assertSame($notFound, $this->answer());
	}//end testARequestTheResidentMayNotAnswerIsNotFound()

	/**
	 * The open request may be named by its uuid or its @self id.
	 *
	 * @return void
	 */
	public function testTheOpenRequestIsMatchedByAnyOfItsIds(): void {
		$this->open = ['uuid' => 'req-1'];
		$this->assertSame(200, $this->answer()['status']);

		$this->open = ['@self' => ['id' => 'req-1']];
		$this->assertSame(200, $this->answer()['status']);
	}//end testTheOpenRequestIsMatchedByAnyOfItsIds()

	/**
	 * A failing request check is logged and answers 404.
	 *
	 * @return void
	 */
	public function testAFailingCheckIsLoggedAndNotFound(): void {
		$this->findFailure = new RuntimeException('down');
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: a portal answer could not check its request',
			['app' => 'dossiq', 'error' => 'down']
		);

		$this->assertSame(['status' => 404, 'body' => ['error' => 'not_found']], $this->answer());
	}//end testAFailingCheckIsLoggedAndNotFound()

	/**
	 * A refused write is logged and answers 503, and the handler is not told.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsUnavailable(): void {
		$this->requests->method('write')->willThrowException(new RefusedException('request-closed', 'The request is closed.'));
		$this->acts->expects($this->never())->method('recordWrite');
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: a portal answer could not be recorded',
			['app' => 'dossiq', 'case' => 'case-1', 'rule' => 'request_closed']
		);

		$this->assertSame(['status' => 503, 'body' => ['error' => 'unavailable']], $this->answer());
	}//end testARefusedWriteIsUnavailable()
}//end class
