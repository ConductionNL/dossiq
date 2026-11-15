<?php

/**
 * ProjectsOntoFlows unit tests
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ProjectsOntoFlows;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A host exposing the trait's projection seams.
 */
class FlowProjectionHost {

	use ProjectsOntoFlows;

	private const MARKER_PREFIX = 'dossiq:test:';

	/**
	 * @param ContainerInterface $container The container FlowService is read from.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * @return object|null The service.
	 */
	public function service(): ?object {
		return $this->flowService();
	}//end service()

	/**
	 * @param object $flowService The service.
	 * @return array<string, string> Marker to uuid.
	 */
	public function index(object $flowService): array {
		return $this->existingByMarker(flowService: $flowService);
	}//end index()

	/**
	 * @param object               $flowService The service.
	 * @param array<string, mixed> $document    The document.
	 * @param string               $marker      The marker.
	 * @param string|null          $uuid        The uuid.
	 * @return array<string, string> The outcome row.
	 */
	public function write(object $flowService, array $document, string $marker, ?string $uuid): array {
		return $this->writeFlow(flowService: $flowService, document: $document, marker: $marker, uuid: $uuid);
	}//end write()

	/**
	 * @param mixed $value The stored value.
	 * @return array<int, array<string, mixed>> The list.
	 */
	public function decode(mixed $value): array {
		return $this->decodeList(value: $value);
	}//end decode()
}//end class

/**
 * A flow as FlowService answers one.
 */
class ProjectedFlow {

	/**
	 * @param string|null $notes The provenance notes.
	 * @param string      $uuid  The uuid.
	 */
	public function __construct(
		private ?string $notes,
		private string $uuid,
	) {
	}//end __construct()

	/**
	 * @return string|null The notes.
	 */
	public function getNotes(): ?string {
		return $this->notes;
	}//end getNotes()

	/**
	 * @return string The uuid.
	 */
	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()
}//end class

/**
 * A FlowService fake that pages through fixed flows and records its calls.
 */
class PagingFlowService {

	/** @var array<int, array<int, mixed>> */
	public array $findCalls = [];

	/** @var array<int, array<int, mixed>> */
	public array $saveCalls = [];

	/**
	 * @param array<int, mixed>   $flows The flows held.
	 * @param RuntimeException|null $fail What save throws, if anything.
	 */
	public function __construct(
		private array $flows,
		private ?RuntimeException $fail = null,
	) {
	}//end __construct()

	/**
	 * @return array<int, mixed>|string The page.
	 */
	public function findAll(string $app, mixed $a, mixed $b, int $limit, int $offset): array|string {
		$this->findCalls[] = [$app, $a, $b, $limit, $offset];
		return array_slice($this->flows, $offset, $limit);
	}//end findAll()

	/**
	 * @param array<string, mixed> $document The document.
	 * @param string|null          $uuid     The uuid.
	 * @return ProjectedFlow The flow.
	 */
	public function save(array $document, ?string $uuid): ProjectedFlow {
		$this->saveCalls[] = [$document, $uuid];
		if ($this->fail !== null) {
			throw $this->fail;
		}

		return new ProjectedFlow(notes: null, uuid: ($uuid ?? 'new-uuid'));
	}//end save()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Support\ProjectsOntoFlows
 * @uses \OCA\Dossiq\Service\Support\RowList
 */
class ProjectsOntoFlowsTest extends TestCase {

	/**
	 * FlowService is read under its full name, Flow segment included.
	 *
	 * @return void
	 */
	public function testFlowServiceIsResolvedByItsFullName(): void {
		$service   = new \stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())
			->method('get')
			->with('OCA\\OpenRegister\\Service\\Flow\\FlowService')
			->willReturn($service);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		self::assertSame($service, (new FlowProjectionHost(container: $container, logger: $logger))->service());
	}//end testFlowServiceIsResolvedByItsFullName()

	/**
	 * An absent FlowService is null and a warning, never a crash.
	 *
	 * @return void
	 */
	public function testAnAbsentFlowServiceIsNullAndLogged(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Flow projection could not resolve FlowService: not registered', ['app' => 'dossiq']);

		self::assertNull((new FlowProjectionHost(container: $container, logger: $logger))->service());
	}//end testAnAbsentFlowServiceIsNullAndLogged()

	/**
	 * Every page is read, and only flows carrying the marker prefix are indexed.
	 *
	 * @return void
	 */
	public function testFlowsAreIndexedByMarkerAcrossPages(): void {
		$flows = [];
		for ($i = 0; $i < 100; $i++) {
			$flows[] = new ProjectedFlow(notes: 'someone else', uuid: 'other-'.$i);
		}

		$flows[5]  = new ProjectedFlow(notes: 'dossiq:test:a', uuid: 'uuid-a');
		$flows[99] = new ProjectedFlow(notes: null, uuid: 'no-notes');
		$flows[]   = new ProjectedFlow(notes: 'dossiq:test:b', uuid: 'uuid-b');

		$service = new PagingFlowService(flows: $flows);
		$host    = new FlowProjectionHost(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		self::assertSame(['dossiq:test:a' => 'uuid-a', 'dossiq:test:b' => 'uuid-b'], $host->index(flowService: $service));
		self::assertSame([['dossiq', null, null, 100, 0], ['dossiq', null, null, 100, 100]], $service->findCalls);
	}//end testFlowsAreIndexedByMarkerAcrossPages()

	/**
	 * A full last page is followed by one more read, which ends on empty.
	 *
	 * @return void
	 */
	public function testAFullLastPageEndsOnTheEmptyPageAfterIt(): void {
		$flows = [];
		for ($i = 0; $i < 100; $i++) {
			$flows[] = new ProjectedFlow(notes: 'dossiq:test:'.$i, uuid: 'u'.$i);
		}

		$service = new PagingFlowService(flows: $flows);
		$host    = new FlowProjectionHost(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		self::assertCount(100, $host->index(flowService: $service));
		self::assertCount(2, $service->findCalls);
		self::assertSame(100, $service->findCalls[1][4]);
	}//end testAFullLastPageEndsOnTheEmptyPageAfterIt()

	/**
	 * A service answering a non-list indexes nothing.
	 *
	 * @return void
	 */
	public function testANonListPageIndexesNothing(): void {
		$service = new class {
			/**
			 * @return string Not a page.
			 */
			public function findAll(): string {
				return 'broken';
			}//end findAll()
		};
		$host = new FlowProjectionHost(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		self::assertSame([], $host->index(flowService: $service));
	}//end testANonListPageIndexesNothing()

	/**
	 * A write without a uuid creates, with one updates, and names the flow.
	 *
	 * @return void
	 */
	public function testAWriteReportsCreatedOrUpdated(): void {
		$service = new PagingFlowService(flows: []);
		$host    = new FlowProjectionHost(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		self::assertSame(
			['outcome' => 'created', 'marker' => 'dossiq:test:a', 'detail' => 'flow new-uuid'],
			$host->write(flowService: $service, document: ['name' => 'A'], marker: 'dossiq:test:a', uuid: null)
		);
		self::assertSame(
			['outcome' => 'updated', 'marker' => 'dossiq:test:b', 'detail' => 'flow uuid-b'],
			$host->write(flowService: $service, document: ['name' => 'B'], marker: 'dossiq:test:b', uuid: 'uuid-b')
		);
		self::assertSame([[['name' => 'A'], null], [['name' => 'B'], 'uuid-b']], $service->saveCalls);
	}//end testAWriteReportsCreatedOrUpdated()

	/**
	 * A failing write is logged and reported as failed, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsReportedNotThrown(): void {
		$service = new PagingFlowService(flows: [], fail: new RuntimeException('validation failed'));
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'Dossiq: could not project onto a flow',
				['app' => 'dossiq', 'marker' => 'dossiq:test:a', 'exception' => 'validation failed']
			);
		$host = new FlowProjectionHost(container: $this->createMock(ContainerInterface::class), logger: $logger);

		self::assertSame(
			['outcome' => 'failed', 'marker' => 'dossiq:test:a', 'detail' => 'validation failed'],
			$host->write(flowService: $service, document: [], marker: 'dossiq:test:a', uuid: 'x')
		);
	}//end testAFailingWriteIsReportedNotThrown()

	/**
	 * A JSON-encoded list and a native list decode to the same rows.
	 *
	 * @return void
	 */
	public function testStoredListsDecodeFromJsonOrArrays(): void {
		$host = new FlowProjectionHost(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		self::assertSame([['id' => 's1']], $host->decode(value: '[{"id":"s1"}]'));
		self::assertSame([['id' => 's1']], $host->decode(value: [['id' => 's1'], 'stray']));
		self::assertSame([], $host->decode(value: 'not json'));
		self::assertSame([], $host->decode(value: null));
	}//end testStoredListsDecodeFromJsonOrArrays()
}//end class
