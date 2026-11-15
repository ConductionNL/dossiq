<?php

/**
 * What OpenRegister's party indicator guard says about a case and a party.
 *
 * The reader never refuses on its own: no guard, a partial guard, a guard
 * that throws or answers something that is not a list all read as "no
 * indicator", and the act proceeds to whatever OpenRegister itself refuses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\PartyIndicatorReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\People\PartyIndicatorReader
 */
class PartyIndicatorReaderTest extends TestCase {

	/**
	 * The guard class the reader asks OpenRegister for.
	 *
	 * @var string
	 */
	private const GUARD = 'OCA\\OpenRegister\\Service\\Party\\PartyIndicatorGuard';

	/**
	 * The logger the reader reports to.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A guard answering the given indicators and send label, recording what it was asked.
	 *
	 * @param mixed $indicators What indicatorsForObject() answers, or a Throwable it throws.
	 * @param mixed $label      What sendRefusalFor() answers, or a Throwable it throws.
	 *
	 * @return object
	 */
	private function guard(mixed $indicators = [], mixed $label = null): object {
		return new class($indicators, $label) {
			/**
			 * The ids each method was asked about.
			 *
			 * @var array<string, array<int, string>>
			 */
			public array $asked = ['indicators' => [], 'send' => []];

			/**
			 * Build the guard.
			 *
			 * @param mixed $indicators The indicators answer.
			 * @param mixed $label      The send answer.
			 */
			public function __construct(private mixed $indicators, private mixed $label) {
			}//end __construct()

			/**
			 * The indicators on an object.
			 *
			 * @param string $id The object id.
			 *
			 * @return mixed
			 */
			public function indicatorsForObject(string $id): mixed {
				$this->asked['indicators'][] = $id;
				if ($this->indicators instanceof \Throwable) {
					throw $this->indicators;
				}

				return $this->indicators;
			}//end indicatorsForObject()

			/**
			 * The refusal label of a send to a party.
			 *
			 * @param string $partyUuid The party.
			 *
			 * @return mixed
			 */
			public function sendRefusalFor(string $partyUuid): mixed {
				$this->asked['send'][] = $partyUuid;
				if ($this->label instanceof \Throwable) {
					throw $this->label;
				}

				return $this->label;
			}//end sendRefusalFor()
		};
	}//end guard()

	/**
	 * A reader over the given guard (or none).
	 *
	 * @param object|null $guard What OpenRegister resolves the guard class to.
	 *
	 * @return PartyIndicatorReader
	 */
	private function reader(?object $guard): PartyIndicatorReader {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(self::GUARD)->willReturn($guard);

		return new PartyIndicatorReader(settingsService: $settings, logger: $this->logger);
	}//end reader()

	/**
	 * The indicators on a case are the guard's rows, non-rows dropped and reindexed.
	 *
	 * @return void
	 */
	public function testTheIndicatorsAreTheGuardsRows(): void {
		$guard = $this->guard(indicators: [
			'a' => ['label' => 'Geheimhouding', 'effect' => 'refuse-send'],
			'b' => 'not-a-row',
			'c' => ['label' => 'Onder curatele', 'effect' => 'show'],
		]);

		self::assertSame(
			expected: [
				['label' => 'Geheimhouding', 'effect' => 'refuse-send'],
				['label' => 'Onder curatele', 'effect' => 'show'],
			],
			actual: $this->reader(guard: $guard)->indicatorsOn(caseId: 'case-1'),
		);
		self::assertSame(expected: ['case-1'], actual: $guard->asked['indicators']);
	}//end testTheIndicatorsAreTheGuardsRows()

	/**
	 * No guard, a partial guard or a non-list answer reads as no indicators.
	 *
	 * @return void
	 */
	public function testNoUsableGuardReadsAsNoIndicators(): void {
		$partial = new class {
			/**
			 * Only one of the two questions.
			 *
			 * @param string $id The object id.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function indicatorsForObject(string $id): array {
				return [['effect' => 'refuse-publication']];
			}//end indicatorsForObject()
		};

		self::assertSame(expected: [], actual: $this->reader(guard: null)->indicatorsOn(caseId: 'case-1'));
		self::assertSame(expected: [], actual: $this->reader(guard: $partial)->indicatorsOn(caseId: 'case-1'));
		self::assertNull(actual: $this->reader(guard: $partial)->publicationRefusal(caseId: 'case-1'));
		self::assertSame(
			expected: [],
			actual: $this->reader(guard: $this->guard(indicators: 'nothing'))->indicatorsOn(caseId: 'case-1'),
		);
	}//end testNoUsableGuardReadsAsNoIndicators()

	/**
	 * A guard that throws on the indicators reads as none, with a warning naming the case.
	 *
	 * @return void
	 */
	public function testAThrowingGuardReadsAsNoIndicatorsAndWarns(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains(string: 'could not answer the indicators on a case'),
				['caseId' => 'case-1'],
			);

		$guard = $this->guard(indicators: new RuntimeException('down'));

		self::assertSame(expected: [], actual: $this->reader(guard: $guard)->indicatorsOn(caseId: 'case-1'));
	}//end testAThrowingGuardReadsAsNoIndicatorsAndWarns()

	/**
	 * The publication refusal is the first indicator with that effect.
	 *
	 * @return void
	 */
	public function testThePublicationRefusalIsTheFirstRefusingIndicator(): void {
		$guard = $this->guard(indicators: [
			['label' => 'Shown only', 'effect' => 'show'],
			['label' => 'Geheim', 'effect' => PartyIndicatorReader::EFFECT_REFUSE_PUBLICATION],
			['label' => 'Also geheim', 'effect' => PartyIndicatorReader::EFFECT_REFUSE_PUBLICATION],
		]);

		self::assertSame(
			expected: ['label' => 'Geheim', 'effect' => 'refuse-publication'],
			actual: $this->reader(guard: $guard)->publicationRefusal(caseId: 'case-1'),
		);

		$none = $this->guard(indicators: [['label' => 'Send only', 'effect' => PartyIndicatorReader::EFFECT_REFUSE_SEND]]);
		self::assertNull(actual: $this->reader(guard: $none)->publicationRefusal(caseId: 'case-1'));
	}//end testThePublicationRefusalIsTheFirstRefusingIndicator()

	/**
	 * A send refusal is the guard's label for that party.
	 *
	 * @return void
	 */
	public function testASendRefusalIsTheGuardsLabel(): void {
		$guard = $this->guard(label: 'Geheimhouding');

		self::assertSame(
			expected: 'Geheimhouding',
			actual: $this->reader(guard: $guard)->sendRefusalFor(partyUuid: 'party-1'),
		);
		self::assertSame(expected: ['party-1'], actual: $guard->asked['send']);
	}//end testASendRefusalIsTheGuardsLabel()

	/**
	 * No party, no guard, or a blank or non-text label is no refusal, and a blank
	 * party never reaches the guard.
	 *
	 * @return void
	 */
	public function testNoLabelIsNoSendRefusal(): void {
		$guard = $this->guard(label: 'Geheimhouding');
		self::assertNull(actual: $this->reader(guard: $guard)->sendRefusalFor(partyUuid: '  '));
		self::assertSame(expected: [], actual: $guard->asked['send']);

		self::assertNull(actual: $this->reader(guard: null)->sendRefusalFor(partyUuid: 'party-1'));
		self::assertNull(
			actual: $this->reader(guard: $this->guard(label: '   '))->sendRefusalFor(partyUuid: 'party-1'),
		);
		self::assertNull(
			actual: $this->reader(guard: $this->guard(label: true))->sendRefusalFor(partyUuid: 'party-1'),
		);
	}//end testNoLabelIsNoSendRefusal()

	/**
	 * A guard that throws on a send reads as no refusal, with a warning naming the party.
	 *
	 * @return void
	 */
	public function testAThrowingGuardIsNoSendRefusalAndWarns(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains(string: 'could not say whether a send to a party is refused'),
				['partyUuid' => 'party-1'],
			);

		$guard = $this->guard(label: new RuntimeException('down'));

		self::assertNull(actual: $this->reader(guard: $guard)->sendRefusalFor(partyUuid: 'party-1'));
	}//end testAThrowingGuardIsNoSendRefusalAndWarns()

	/**
	 * The party of a link is its trimmed partyUuid, or blank.
	 *
	 * @return void
	 */
	public function testThePartyOfALinkIsItsTrimmedUuid(): void {
		$reader = $this->reader(guard: null);

		self::assertSame(expected: 'party-1', actual: $reader->partyUuidOf(link: ['partyUuid' => ' party-1 ']));
		self::assertSame(expected: '', actual: $reader->partyUuidOf(link: ['person' => 'party-1']));
	}//end testThePartyOfALinkIsItsTrimmedUuid()
}//end class
