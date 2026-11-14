<?php

/**
 * Unit tests for PartyRefusalReader: a send or a publication is refused when
 * OpenRegister or pipelinq says so, pipelinq's label wins the sentence, and a
 * pipelinq that does not answer refuses nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\People\PartyIndicatorReader;
use OCA\Dossiq\Service\Pipelinq\PartyRefusalReader;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Pipelinq\PartyRefusalReader
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 */
class PartyRefusalReaderTest extends TestCase {

	private PartyIndicatorReader&MockObject $openRegister;

	protected function setUp(): void {
		parent::setUp();
		$this->openRegister = $this->createMock(PartyIndicatorReader::class);
	}//end setUp()

	/**
	 * A reader whose gateway reaches the given indicator service, or none.
	 *
	 * @param object|null $indicators Pipelinq's indicator service.
	 */
	private function reader(?object $indicators): PartyRefusalReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($indicators): object {
				if ($id !== PipelinqGateway::PARTY_INDICATORS || $indicators === null) {
					throw new RuntimeException('nothing answers to ' . $id);
				}

				return $indicators;
			}
		);

		return new PartyRefusalReader(new PipelinqGateway($container, $this->createMock(LoggerInterface::class)), $this->openRegister);
	}//end reader()

	/**
	 * A pipelinq indicator service with fixed answers, recording its calls.
	 *
	 * @param array<string, mixed>|null $blocked What isBlocked answers per party id.
	 * @param mixed                     $resolved What resolve answers.
	 */
	private function indicators(array $blocked = [], mixed $resolved = []): object {
		return new class($blocked, $resolved) {
			/** @var list<array{string, string}> */
			public array $asked = [];

			public function __construct(private array $blocked, private mixed $resolved) {
			}

			public function isBlocked(string $partyId, string $act): mixed {
				$this->asked[] = [$partyId, $act];
				return $this->blocked[$partyId] ?? ['blocked' => false];
			}

			public function resolve(string $partyId): mixed {
				$this->asked[] = [$partyId, 'resolve'];
				return $this->resolved;
			}
		};
	}//end indicators()

	public function testAnEmptyPartyIsNeverAsked(): void {
		$this->openRegister->expects($this->never())->method('sendRefusalFor');
		$indicators = $this->indicators();

		$this->assertSame(['refused' => false, 'sources' => [], 'indicator' => ''], $this->reader($indicators)->maySendTo('  '));
		$this->assertSame([], $indicators->asked);
	}//end testAnEmptyPartyIsNeverAsked()

	public function testBothSourcesRefuseAndPipelinqsLabelWins(): void {
		$this->openRegister->expects($this->once())->method('sendRefusalFor')->with('p-1')->willReturn('Geheimhouding');
		$indicators = $this->indicators(['p-1' => ['blocked' => true, 'indicators' => [['label' => ' Dreiging ', 'code' => 'THR']]]]);

		$verdict = $this->reader($indicators)->maySendTo(' p-1 ');

		$this->assertSame(['refused' => true, 'sources' => ['openregister', 'pipelinq'], 'indicator' => 'Dreiging'], $verdict);
		$this->assertSame([['p-1', PartyRefusalReader::ACT_SEND]], $indicators->asked);
	}//end testBothSourcesRefuseAndPipelinqsLabelWins()

	public function testPipelinqsCodeOrAGenericSentenceStandsInForAMissingLabel(): void {
		$this->openRegister->method('sendRefusalFor')->willReturn(null);
		$reader = $this->reader(
			$this->indicators(
				[
					'p-code' => ['blocked' => true, 'indicators' => [['code' => 'THR']]],
					'p-none' => ['blocked' => true],
				]
			)
		);

		$this->assertSame(['refused' => true, 'sources' => ['pipelinq'], 'indicator' => 'THR'], $reader->maySendTo('p-code'));
		$this->assertSame('an indicator on this party', $reader->maySendTo('p-none')['indicator']);
	}//end testPipelinqsCodeOrAGenericSentenceStandsInForAMissingLabel()

	public function testOnlyOpenRegisterRefusesWhenPipelinqIsAbsentOrNotBlocking(): void {
		$this->openRegister->method('sendRefusalFor')->willReturn('Geheimhouding');

		$expected = ['refused' => true, 'sources' => ['openregister'], 'indicator' => 'Geheimhouding'];
		$this->assertSame($expected, $this->reader(null)->maySendTo('p-1'));
		$this->assertSame($expected, $this->reader($this->indicators(['p-1' => ['blocked' => 'yes']]))->maySendTo('p-1'));
	}//end testOnlyOpenRegisterRefusesWhenPipelinqIsAbsentOrNotBlocking()

	public function testPublicationTakesOpenRegistersLabelOrKeyAndTheFirstBlockingParty(): void {
		$this->openRegister->expects($this->exactly(2))->method('publicationRefusal')->with('case-1')
			->willReturnOnConsecutiveCalls(['key' => 'secret_address'], ['label' => 'Adres afgeschermd', 'key' => 'secret_address']);
		$indicators = $this->indicators(
			[
				'p-2' => ['blocked' => true, 'indicators' => [['label' => 'Afgeschermd']]],
				'p-3' => ['blocked' => true, 'indicators' => [['label' => 'Never asked']]],
			]
		);
		$reader = $this->reader($indicators);

		$this->assertSame(
			['refused' => true, 'sources' => ['openregister', 'pipelinq'], 'indicator' => 'Afgeschermd'],
			$reader->mayPublish(' case-1 ', ['', 'p-1', 'p-2', 'p-3'])
		);
		$this->assertSame(
			[['p-1', PartyRefusalReader::ACT_PUBLISH_ADDRESS], ['p-2', PartyRefusalReader::ACT_PUBLISH_ADDRESS]],
			$indicators->asked
		);
		$this->assertSame(
			['refused' => true, 'sources' => ['openregister'], 'indicator' => 'Adres afgeschermd'],
			$reader->mayPublish('case-1')
		);
	}//end testPublicationTakesOpenRegistersLabelOrKeyAndTheFirstBlockingParty()

	public function testNothingRefusesAPublicationWhenNeitherSourceDoes(): void {
		$this->openRegister->method('publicationRefusal')->willReturn(null);

		$this->assertSame(
			['refused' => false, 'sources' => [], 'indicator' => ''],
			$this->reader($this->indicators())->mayPublish('case-1', ['p-1'])
		);
	}//end testNothingRefusesAPublicationWhenNeitherSourceDoes()

	public function testIndicatorsOnPartyAreAvailableOnlyAsAnArrayAnswer(): void {
		$listed = [['code' => 'THR', 'label' => 'Dreiging']];
		$indicators = $this->indicators([], $listed);

		$this->assertSame(['available' => true, 'indicators' => $listed], $this->reader($indicators)->indicatorsOnParty(' p-1 '));
		$this->assertSame([['p-1', 'resolve']], $indicators->asked);
		$this->assertSame(['available' => false, 'indicators' => []], $this->reader($this->indicators([], 'none'))->indicatorsOnParty('p-1'));
		$this->assertSame(['available' => false, 'indicators' => []], $this->reader(null)->indicatorsOnParty('p-1'));
	}//end testIndicatorsOnPartyAreAvailableOnlyAsAnArrayAnswer()
}//end class
