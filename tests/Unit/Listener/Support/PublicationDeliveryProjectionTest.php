<?php

/**
 * PublicationDeliveryProjection Unit Tests
 *
 * What a concluded delivery writes onto its case: the outcome on the
 * publication that carries the correlation id, saved in the configured
 * register and schema; nothing when the state is already there, when no
 * publication matches, when the case is gone or when there is no key.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\ConcludedDelivery;
use OCA\Dossiq\Listener\Support\PublicationDeliveryProjection;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Listener\DeliveryObjectServiceStub;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../DeliveryConcludedListenerTest.php';

/**
 * Unit tests for PublicationDeliveryProjection.
 *
 * @covers \OCA\Dossiq\Listener\Support\PublicationDeliveryProjection
 * @covers \OCA\Dossiq\Listener\Support\ConcludedDelivery
 */
class PublicationDeliveryProjectionTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The mocked object service.
	 *
	 * @var DeliveryObjectServiceStub&MockObject
	 */
	private DeliveryObjectServiceStub $objects;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnMap([['register', '', 'reg-1'], ['case_schema', '', 'case-1']]);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->objects = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
	}//end setUp()

	/**
	 * The projection under test, over the object service unless told otherwise.
	 *
	 * @param bool $withObjectService Whether OpenRegister's object service is available.
	 *
	 * @return PublicationDeliveryProjection
	 */
	private function projection(bool $withObjectService = true): PublicationDeliveryProjection {
		$objectService = null;
		if ($withObjectService === true) {
			$objectService = $this->objects;
		}

		$this->settings->method('getObjectService')->willReturn($objectService);
		return new PublicationDeliveryProjection(settingsService: $this->settings, logger: $this->logger);
	}//end projection()

	/**
	 * A delivered conclusion for correlation id corr-2.
	 *
	 * @param string $caseId        The case.
	 * @param string $correlationId The correlation id.
	 *
	 * @return ConcludedDelivery
	 */
	private function delivered(string $caseId = 'c1', string $correlationId = 'corr-2'): ConcludedDelivery {
		return new ConcludedDelivery(
			caseId: $caseId,
			correlationId: $correlationId,
			channel: 'gemeenteblad',
			outcome: ['status' => 'delivered', 'attempts' => 2, 'error' => null, 'concludedAt' => '2026-11-26T10:00:00+00:00']
		);
	}//end delivered()

	/**
	 * A case with two publications, the second carrying corr-2.
	 *
	 * @param string $secondStatus The delivery status of the second publication.
	 *
	 * @return array<string, mixed>
	 */
	private function caseWithTwoPublications(string $secondStatus = 'requested'): array {
		return [
			'id' => 'c1',
			'publications' => [
				['channel' => 'staatscourant', 'delivery' => ['status' => 'requested', 'correlationId' => 'corr-1']],
				'not a publication',
				['channel' => 'gemeenteblad', 'delivery' => ['status' => $secondStatus, 'correlationId' => 'corr-2', 'eventId' => 'evt-2']],
			],
		];
	}//end caseWithTwoPublications()

	/**
	 * The outcome lands on the matching publication only, keeps what the delivery held, and is saved in the configured register and schema.
	 *
	 * @return void
	 */
	public function testRecordsTheOutcomeOnTheMatchingPublication(): void {
		$this->objects->expects($this->once())->method('find')
			->with('c1', $this->callback(callback: static fn (ObjectFindOptions $o): bool => $o->register === 'reg-1' && $o->schema === 'case-1'))
			->willReturn($this->caseWithTwoPublications());
		$saved = null;
		$target = null;
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object, ObjectSaveOptions $options) use (&$saved, &$target): array {
				$saved = $object;
				$target = $options->target;
				return $object;
			}
		);

		$this->projection()->project(delivery: $this->delivered());

		$this->assertSame(expected: ['status' => 'requested', 'correlationId' => 'corr-1'], actual: $saved['publications'][0]['delivery']);
		$this->assertSame(
			expected: [
				'status' => 'delivered',
				'correlationId' => 'corr-2',
				'eventId' => 'evt-2',
				'attempts' => 2,
				'error' => null,
				'concludedAt' => '2026-11-26T10:00:00+00:00',
			],
			actual: $saved['publications'][2]['delivery']
		);
		$this->assertSame(expected: 'reg-1', actual: $target->register);
		$this->assertSame(expected: 'case-1', actual: $target->schema);
	}//end testRecordsTheOutcomeOnTheMatchingPublication()

	/**
	 * A state that is already recorded writes nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testAnOutcomeAlreadyRecordedWritesNothing(): void {
		$this->objects->method('find')->willReturn($this->caseWithTwoPublications(secondStatus: 'delivered'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->never())->method('warning');

		$this->projection()->project(delivery: $this->delivered());
	}//end testAnOutcomeAlreadyRecordedWritesNothing()

	/**
	 * No publication with the correlation id: a warning names the case and nothing is saved.
	 *
	 * @return void
	 */
	public function testNoMatchingPublicationIsLoggedAndWritesNothing(): void {
		$this->objects->method('find')->willReturn($this->caseWithTwoPublications());
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq DeliveryConcludedListener: no publication matches the concluded delivery',
			['caseId' => 'c1', 'correlationId' => 'corr-9', 'channel' => 'gemeenteblad']
		);

		$this->projection()->project(delivery: $this->delivered(correlationId: 'corr-9'));
	}//end testNoMatchingPublicationIsLoggedAndWritesNothing()

	/**
	 * A case that is not found is logged; a found entity is read through its JSON form.
	 *
	 * @return void
	 */
	public function testACaseNotFoundIsLoggedAndAnEntityIsReadAsJson(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			null,
			new class ($this->caseWithTwoPublications()) implements \JsonSerializable {
				/**
				 * Constructor.
				 *
				 * @param array<string, mixed> $data The case data.
				 */
				public function __construct(private readonly array $data) {
				}//end __construct()

				/**
				 * The case data.
				 *
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return $this->data;
				}//end jsonSerialize()
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq DeliveryConcludedListener: case not found for concluded delivery',
			['caseId' => 'c1', 'correlationId' => 'corr-2']
		);
		$this->objects->expects($this->once())->method('saveObject')->willReturnArgument(0);

		$projection = $this->projection();
		$projection->project(delivery: $this->delivered());
		$projection->project(delivery: $this->delivered());
	}//end testACaseNotFoundIsLoggedAndAnEntityIsReadAsJson()

	/**
	 * Without a case id, a correlation id or OpenRegister nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAKeyOrOpenRegisterNothingIsRead(): void {
		$this->objects->expects($this->never())->method('find');

		$this->projection()->project(delivery: $this->delivered(caseId: ''));
		$this->projection()->project(delivery: $this->delivered(correlationId: ''));

		$this->setUp();
		$this->objects->expects($this->never())->method('find');
		$this->projection(withObjectService: false)->project(delivery: $this->delivered());
	}//end testWithoutAKeyOrOpenRegisterNothingIsRead()
}//end class
