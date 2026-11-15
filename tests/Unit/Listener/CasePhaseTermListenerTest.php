<?php

/**
 * Dossiq CasePhaseTermListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CasePhaseTermListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\PhaseTermService;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case that moves into a status starts that phase's clock, once.
 *
 * @covers \OCA\Dossiq\Listener\CasePhaseTermListener
 * @uses \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses \OCA\Dossiq\Service\ObjectSchemaSlugResolver
 * @uses \OCA\Dossiq\Service\TermKind
 */
class CasePhaseTermListenerTest extends TestCase {

	/**
	 * The phase service.
	 *
	 * @var PhaseTermService&MockObject
	 */
	private PhaseTermService $phases;

	/**
	 * The term service.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->phases = $this->createMock(PhaseTermService::class);
		$this->terms = $this->createMock(TermijnService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A case in a new status enters that phase, with its case type.
	 *
	 * @return void
	 */
	public function testANewStatusEntersThePhase(): void {
		$this->terms->method('instancesForCase')->with('case-1')->willReturn([]);
		$this->phases->expects($this->once())->method('enterPhase')->with('case-1', 'ct-1', 'st-1');

		$this->listener()->handle($this->updated(['id' => 'case-1', 'status' => 'st-1', 'caseType' => 'ct-1']));
	}//end testANewStatusEntersThePhase()

	/**
	 * References may be embedded objects, and the uuid stands in for the id.
	 *
	 * @return void
	 */
	public function testEmbeddedReferencesAreRead(): void {
		$this->terms->method('instancesForCase')->willReturn([]);
		$this->phases->expects($this->once())->method('enterPhase')->with('case-2', 'ct-2', 'st-2');

		$this->listener()->handle(
			$this->updated(
				[
					'uuid' => 'case-2',
					'status' => ['@self' => ['id' => 'st-2']],
					'caseType' => ['id' => 'ct-2'],
				]
			)
		);
	}//end testEmbeddedReferencesAreRead()

	/**
	 * A running clock for the same phase is not started twice.
	 *
	 * @return void
	 */
	public function testARunningPhaseClockIsNotRestarted(): void {
		$this->terms->method('instancesForCase')->willReturn(
			[
				['kind' => 'phase', 'statusType' => 'st-1', 'status' => 'verlengd'],
			]
		);
		$this->phases->expects($this->never())->method('enterPhase');

		$this->listener()->handle($this->updated(['id' => 'case-1', 'status' => 'st-1', 'caseType' => 'ct-1']));
	}//end testARunningPhaseClockIsNotRestarted()

	/**
	 * A statutory term, another phase's clock or a finished one does not
	 * count as this phase already running.
	 *
	 * @return void
	 */
	public function testOtherClocksDoNotCount(): void {
		$this->terms->method('instancesForCase')->willReturn(
			[
				['kind' => 'statutory', 'statusType' => 'st-1', 'status' => 'lopend'],
				['statusType' => 'st-1', 'status' => 'lopend'],
				['kind' => 'phase', 'statusType' => 'st-0', 'status' => 'lopend'],
				['kind' => 'phase', 'statusType' => 'st-1', 'status' => 'afgerond'],
			]
		);
		$this->phases->expects($this->once())->method('enterPhase')->with('case-1', '', 'st-1');

		$this->listener()->handle($this->updated(['id' => 'case-1', 'status' => 'st-1']));
	}//end testOtherClocksDoNotCount()

	/**
	 * Payloads that start nothing.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function ignored(): array {
		return [
			'not a case' => [['id' => 'x', 'status' => 'st-1'], 'task'],
			'no id' => [['status' => 'st-1'], 'case'],
			'no status' => [['id' => 'case-1', 'status' => ' '], 'case'],
		];
	}//end ignored()

	/**
	 * Another schema, or a case without an id or status, starts nothing.
	 *
	 * @param array<string, mixed> $payload The object.
	 * @param string               $schema  Its schema slug.
	 *
	 * @dataProvider ignored
	 *
	 * @return void
	 */
	public function testNothingStarts(array $payload, string $schema): void {
		$this->terms->expects($this->never())->method('instancesForCase');
		$this->phases->expects($this->never())->method('enterPhase');

		$this->listener()->handle($this->updated($payload, $schema));
	}//end testNothingStarts()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->phases->expects($this->never())->method('enterPhase');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A failure is logged as a warning, not thrown into the save.
	 *
	 * @return void
	 */
	public function testAFailureIsLogged(): void {
		$this->terms->method('instancesForCase')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq phase term: the phase clock could not be moved with the case',
			['error' => 'down']
		);

		$this->listener()->handle($this->updated(['id' => 'case-1', 'status' => 'st-1']));
	}//end testAFailureIsLogged()

	/**
	 * An update event carrying the payload.
	 *
	 * @param array<string, mixed> $payload The object.
	 * @param string               $schema  Its schema slug.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(array $payload, string $schema = 'case'): ObjectUpdatedEvent {
		$payload['@self'] = ['schema' => $schema];

		return new class ($payload) extends ObjectUpdatedEvent {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $payload The object.
			 */
			public function __construct(private readonly array $payload) {
				parent::__construct(new ObjectEntity());
			}

			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->payload;
			}
		};
	}//end updated()

	/**
	 * The listener under test.
	 *
	 * @return CasePhaseTermListener
	 */
	private function listener(): CasePhaseTermListener {
		return new CasePhaseTermListener(
			phases: $this->phases,
			termService: $this->terms,
			slugResolver: new ObjectSchemaSlugResolver(
				container: $this->createMock(ContainerInterface::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			logger: $this->logger,
		);
	}//end listener()
}//end class
