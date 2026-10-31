<?php

/**
 * Unit tests for BagObjectReader.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registry;

use OCA\Dossiq\Service\Registry\BagObjectReader;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Dossiq holds no BAG client. It asks integriq's BAG read by class name at
 * runtime, and an integriq that is absent, too old or silent is "not
 * available", never "not found".
 *
 * @covers \OCA\Dossiq\Service\Registry\BagObjectReader
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class BagObjectReaderTest extends TestCase {
	/**
	 * The class integriq ships the read under.
	 */
	private const INTEGRIQ_CLASS = 'OCA\\Integriq\\PropertySource\\Provider\\BagObjectReader';

	/**
	 * The class names the container was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * A reader over an instance where integriq is, or is not, installed.
	 *
	 * @param bool $installed Whether the app manager knows integriq.
	 * @param object|null $integriqReader What the container holds under integriq's class name.
	 *
	 * @return BagObjectReader
	 */
	private function reader(bool $installed, ?object $integriqReader): BagObjectReader {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $installed === true && $id === 'integriq');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($integriqReader): object {
				$this->asked[] = $class;
				if ($class !== self::INTEGRIQ_CLASS || $integriqReader === null) {
					throw new RuntimeException('not registered');
				}

				return $integriqReader;
			}
		);

		return new BagObjectReader($container, $apps, $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * An integriq BAG read that answers what it is told to.
	 *
	 * @param mixed $answer The answer, or a Throwable to throw.
	 *
	 * @return object
	 */
	private static function integriq(mixed $answer): object {
		return new class($answer) {
			/**
			 * What this double was asked: type and identificatie.
			 *
			 * @var array<int, array<int, string>>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private readonly mixed $answer) {
			}//end __construct()

			/**
			 * The read, with integriq's signature.
			 *
			 * @param string $type The BAG object type.
			 * @param string $identificatie The id.
			 *
			 * @return mixed
			 */
			public function read(string $type, string $identificatie): mixed {
				$this->asked[] = [$type, $identificatie];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end read()
		};
	}//end integriq()

	/**
	 * Integriq's answer, as its BagObjectReader gives it, with its object.
	 *
	 * @param bool $available Answered.
	 * @param bool $found Found.
	 * @param string $reason Why not.
	 * @param bool $simulated From a mock client.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(bool $available, bool $found, string $reason = '', bool $simulated = false): array {
		return ['available' => $available, 'reason' => $reason, 'found' => $found, 'simulated' => $simulated, 'object' => null];
	}//end answer()

	/**
	 * The lookup uses integriq's own app id and namespace, and passes type and id through.
	 *
	 * @return void
	 */
	public function testIntegriqIsAskedUnderItsOwnName(): void {
		$integriq = self::integriq(self::answer(available: true, found: true));
		$result = $this->reader(true, $integriq)->read('nummeraanduiding', '0363200000218908');

		$this->assertSame(self::INTEGRIQ_CLASS, $this->asked[0]);
		$this->assertContains(self::INTEGRIQ_CLASS, FleetAppId::classCandidates('integriq', BagObjectReader::INTEGRIQ_READER));
		$this->assertSame([['nummeraanduiding', '0363200000218908']], $integriq->asked);
		$this->assertSame(['available' => true, 'reason' => '', 'found' => true, 'simulated' => false], $result);
	}//end testIntegriqIsAskedUnderItsOwnName()

	/**
	 * "Not found" travels as an answer, not as a failure.
	 *
	 * @return void
	 */
	public function testNotFoundIsAnAnswer(): void {
		$result = $this->reader(true, self::integriq(self::answer(available: true, found: false)))->read('pand', '0363100012167579');

		$this->assertSame(['available' => true, 'reason' => '', 'found' => false, 'simulated' => false], $result);
	}//end testNotFoundIsAnAnswer()

	/**
	 * Without integriq the read is not available, and nothing is looked up.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheReadIsNotAvailable(): void {
		$result = $this->reader(false, self::integriq(self::answer(available: true, found: true)))->read('nummeraanduiding', '0363200000218908');

		$this->assertSame(['available' => false, 'reason' => BagObjectReader::REASON_NO_INTEGRIQ, 'found' => false, 'simulated' => false], $result);
		$this->assertSame([], $this->asked);
	}//end testWithoutIntegriqTheReadIsNotAvailable()

	/**
	 * An integriq from before the BAG read is not available either.
	 *
	 * @return void
	 */
	public function testAnIntegriqWithoutTheReadIsNotAvailable(): void {
		$result = $this->reader(true, null)->read('nummeraanduiding', '0363200000218908');

		$this->assertFalse($result['available']);
		$this->assertSame(BagObjectReader::REASON_NO_READER, $result['reason']);
	}//end testAnIntegriqWithoutTheReadIsNotAvailable()

	/**
	 * Integriq's reason and its simulated flag travel.
	 *
	 * @return void
	 */
	public function testIntegriqsReasonAndSimulatedFlagTravel(): void {
		$result = $this->reader(true, self::integriq(self::answer(available: false, found: false, reason: 'unclear-answer', simulated: true)))
			->read('nummeraanduiding', '0363200000218908');

		$this->assertSame(['available' => false, 'reason' => 'unclear-answer', 'found' => false, 'simulated' => true], $result);
	}//end testIntegriqsReasonAndSimulatedFlagTravel()

	/**
	 * A throwing read, or an answer in a shape this class does not know, is unanswered.
	 *
	 * @return void
	 */
	public function testAnAnswerThatCannotBeReadIsUnanswered(): void {
		$answers = [
			new RuntimeException('PDOK said no'),
			'yes',
			['available' => true],
			['available' => 'true', 'found' => true],
		];

		foreach ($answers as $answer) {
			$result = $this->reader(true, self::integriq($answer))->read('nummeraanduiding', '0363200000218908');

			$this->assertFalse($result['available']);
			$this->assertFalse($result['found']);
			$this->assertSame(BagObjectReader::REASON_UNREACHABLE, $result['reason']);
		}
	}//end testAnAnswerThatCannotBeReadIsUnanswered()

	/**
	 * An answer that does not say whether it is simulated is treated as simulated.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutTheSimulatedFlagIsTreatedAsSimulated(): void {
		$result = $this->reader(true, self::integriq(['available' => true, 'reason' => '', 'found' => true]))
			->read('nummeraanduiding', '0363200000218908');

		$this->assertTrue($result['simulated']);
	}//end testAnAnswerWithoutTheSimulatedFlagIsTreatedAsSimulated()
}//end class
