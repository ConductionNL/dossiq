<?php

/**
 * Unit tests for PersonRelationReader.
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
 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registry;

use OCA\Dossiq\Service\Registry\PersonRelationReader;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Dossiq holds no BRP client. It asks integriq's relation read by class name
 * at runtime, and an integriq that is absent, too old or silent is "not
 * available", never "no relations".
 *
 * @covers \OCA\Dossiq\Service\Registry\PersonRelationReader
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class PersonRelationReaderTest extends TestCase {
	/**
	 * The class integriq ships the read under.
	 */
	private const INTEGRIQ_CLASS = 'OCA\\Integriq\\PropertySource\\Provider\\BrpRelationReader';

	/**
	 * Everything handed to the logger.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

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
	 * @return PersonRelationReader
	 */
	private function reader(bool $installed, ?object $integriqReader): PersonRelationReader {
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

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (...$arguments): void {
					$this->logged[] = $arguments;
				}
			);
		}

		return new PersonRelationReader($container, $apps, $logger);
	}//end reader()

	/**
	 * An integriq relation read that answers what it is told to.
	 *
	 * @param mixed $answer The answer, or a Throwable to throw.
	 *
	 * @return object
	 */
	private static function integriq(mixed $answer): object {
		return new class($answer) {
			/**
			 * The numbers this double was asked about.
			 *
			 * @var array<int, string>
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
			 * @param string $burgerservicenummer The person.
			 * @param array<string, mixed> $config Configuration.
			 *
			 * @return mixed
			 */
			public function read(string $burgerservicenummer, array $config = []): mixed {
				$this->asked[] = $burgerservicenummer;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end read()
		};
	}//end integriq()

	/**
	 * The lookup uses integriq's own app id and namespace.
	 *
	 * @return void
	 */
	public function testIntegriqIsAskedUnderItsOwnName(): void {
		$integriq = self::integriq(['available' => true, 'reason' => '', 'relations' => [['relation' => 'partner', 'burgerservicenummer' => '999993653']]]);
		$result = $this->reader(true, $integriq)->read('999990019');

		$this->assertSame(self::INTEGRIQ_CLASS, $this->asked[0]);
		$this->assertContains(self::INTEGRIQ_CLASS, FleetAppId::classCandidates('integriq', PersonRelationReader::INTEGRIQ_READER));
		$this->assertSame(['999990019'], $integriq->asked);
		$this->assertSame(
			['available' => true, 'reason' => '', 'relations' => [['relation' => 'partner', 'burgerservicenummer' => '999993653']]],
			$result
		);
	}//end testIntegriqIsAskedUnderItsOwnName()

	/**
	 * Without integriq the read is not available, and nothing is looked up.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheReadIsNotAvailable(): void {
		$result = $this->reader(false, self::integriq(['available' => true, 'reason' => '', 'relations' => []]))->read('999990019');

		$this->assertSame(['available' => false, 'reason' => PersonRelationReader::REASON_NO_INTEGRIQ, 'relations' => []], $result);
		$this->assertSame([], $this->asked);
	}//end testWithoutIntegriqTheReadIsNotAvailable()

	/**
	 * An integriq from before the relation read is not available either.
	 *
	 * @return void
	 */
	public function testAnIntegriqWithoutTheReadIsNotAvailable(): void {
		$this->assertSame(
			['available' => false, 'reason' => PersonRelationReader::REASON_NO_READER, 'relations' => []],
			$this->reader(true, null)->read('999990019')
		);
		$this->assertSame(
			PersonRelationReader::REASON_NO_READER,
			$this->reader(true, new \stdClass())->read('999990019')['reason']
		);
	}//end testAnIntegriqWithoutTheReadIsNotAvailable()

	/**
	 * Integriq's own reason travels: a source without the setting says so.
	 *
	 * @return void
	 */
	public function testIntegriqsReasonTravels(): void {
		$result = $this->reader(true, self::integriq(['available' => false, 'reason' => 'relations-not-enabled', 'relations' => []]))->read('999990019');

		$this->assertSame(['available' => false, 'reason' => 'relations-not-enabled', 'relations' => []], $result);
	}//end testIntegriqsReasonTravels()

	/**
	 * A read that throws, or answers something that is not the agreed shape,
	 * is unanswered. It is never an empty family.
	 *
	 * @param mixed $answer What integriq did.
	 *
	 * @return void
	 *
	 * @dataProvider unusable
	 */
	public function testAnAnswerThatCannotBeReadIsUnanswered(mixed $answer): void {
		$result = $this->reader(true, self::integriq($answer))->read('999990019');

		$this->assertSame(['available' => false, 'reason' => PersonRelationReader::REASON_UNREACHABLE, 'relations' => []], $result);
	}//end testAnAnswerThatCannotBeReadIsUnanswered()

	/**
	 * Answers that cannot be relied on.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable(): array {
		return [
			'throws' => [new RuntimeException('registry said 999990019')],
			'not an array' => ['partner'],
			'no available flag' => [['relations' => []]],
			'available is not a boolean' => [['available' => 'yes', 'relations' => []]],
			'available without a relation list' => [['available' => true, 'reason' => '']],
			'a relation row that is not a row' => [['available' => true, 'reason' => '', 'relations' => ['999993653']]],
			'a relation row without a number' => [['available' => true, 'reason' => '', 'relations' => [['relation' => 'partner']]]],
		];
	}//end unusable()

	/**
	 * No number and no part of an answer or failure reaches the log.
	 *
	 * @return void
	 */
	public function testNoNumberIsLogged(): void {
		$this->reader(true, self::integriq(new RuntimeException('registry said 999990019')))->read('999990019');
		$this->reader(true, self::integriq(['available' => true, 'reason' => '', 'relations' => [['relation' => 'partner', 'burgerservicenummer' => '999993653']]]))->read('999990019');
		$this->reader(false, null)->read('999990019');

		$written = (string)json_encode($this->logged);
		$this->assertStringNotContainsString('999990019', $written);
		$this->assertStringNotContainsString('999993653', $written);
	}//end testNoNumberIsLogged()
}//end class
