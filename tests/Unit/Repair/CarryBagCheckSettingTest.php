<?php

/**
 * Carry BAG Check Setting Test
 *
 * The step under test is the one appinfo/info.xml registers, found by its
 * place in the post-migration block, not a class this test names on its own.
 * A step that works but is not registered never runs on upgrade; this test
 * goes red for that too.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-an-instance-that-checked-bag-locations-before-the-upgrade-keeps-checking-them
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;

/**
 * Upgrade behaviour of the step that carries the BAG check setting.
 */
final class CarryBagCheckSettingTest extends TestCase {
	/**
	 * Short name of the step this test looks for in appinfo/info.xml.
	 */
	private const STEP = 'CarryBagCheckSetting';

	/**
	 * App config rows of the dossiq namespace, key to value.
	 *
	 * @var array<string,string>
	 */
	private array $store = [];

	/**
	 * Whether every config write throws.
	 *
	 * @var bool
	 */
	private bool $writeThrows = false;

	/**
	 * Reset the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->writeThrows = false;
	}//end setUp()

	/**
	 * The class appinfo/info.xml registers under post-migration for this step.
	 *
	 * @return string
	 */
	private function registeredClass(): string {
		$xml = simplexml_load_string((string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		self::assertInstanceOf(SimpleXMLElement::class, $xml, 'appinfo/info.xml must parse.');

		$found = [];
		foreach (($xml->{'repair-steps'}->{'post-migration'}->step ?? []) as $step) {
			$class = trim((string)$step);
			if (str_ends_with($class, '\\' . self::STEP) === true) {
				$found[] = $class;
			}
		}

		self::assertCount(1, $found, 'appinfo/info.xml must register ' . self::STEP . ' exactly once under post-migration, or it never runs on upgrade.');
		self::assertTrue(class_exists($found[0]), 'The registered class ' . $found[0] . ' must exist.');

		return $found[0];
	}//end registeredClass()

	/**
	 * The registered step over the in-memory app config.
	 *
	 * @return IRepairStep
	 */
	private function step(): IRepairStep {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturnCallback(
			fn (string $app, string $key): bool => $app === 'dossiq' && array_key_exists($key, $this->store)
		);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'dossiq' ? ($this->store[$key] ?? $default) : $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->writeThrows === true) {
					throw new RuntimeException('the configuration table is not writable');
				}

				self::assertSame('dossiq', $app);
				$this->store[$key] = $value;
				return true;
			}
		);

		$class = $this->registeredClass();
		$step = new $class(
			appConfig: $appConfig,
			integrationMode: new IntegrationMode(appConfig: $appConfig),
			logger: $this->createMock(LoggerInterface::class),
		);
		self::assertInstanceOf(IRepairStep::class, $step);

		return $step;
	}//end step()

	/**
	 * Old settings under which the deleted client asked the BAG.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function checkedBefore(): array {
		return [
			'Kadaster, test' => [['integration.bag.mode' => 'test']],
			'Kadaster, live' => [['integration.bag.mode' => 'live']],
			'Kadaster, live in other case and spacing' => [['integration.bag.mode' => ' Live ']],
			'PDOK mirror' => [['integration.bag.source' => 'pdok']],
			'PDOK mirror with a dormant mode' => [['integration.bag.mode' => 'log', 'integration.bag.source' => 'pdok']],
		];
	}//end checkedBefore()

	/**
	 * Old settings under which the deleted client asked nothing.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function notCheckedBefore(): array {
		return [
			'no BAG setting at all' => [[]],
			'dormant mode' => [['integration.bag.mode' => 'log']],
			'a mode the client did not accept' => [['integration.bag.mode' => 'preprod']],
			'another source' => [['integration.bag.source' => 'kadaster']],
			'only a key and an address' => [['integration.bag.apiKey' => 'k', 'integration.bag.baseUrl' => 'https://example.nl']],
		];
	}//end notCheckedBefore()

	/**
	 * An instance that ran the check keeps running it.
	 *
	 * @param array<string,string> $old The old settings.
	 *
	 * @return void
	 */
	#[DataProvider('checkedBefore')]
	public function testAnInstanceThatCheckedBeforeKeepsChecking(array $old): void {
		$this->store = $old;

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame('on', $this->store['integration.bag.check'] ?? null);
	}//end testAnInstanceThatCheckedBeforeKeepsChecking()

	/**
	 * Every other instance is left off, with no key written.
	 *
	 * @param array<string,string> $old The old settings.
	 *
	 * @return void
	 */
	#[DataProvider('notCheckedBefore')]
	public function testEveryOtherInstanceIsLeftOff(array $old): void {
		$this->store = $old;

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame($old, $this->store, 'No key may be written where the old client asked nothing.');
	}//end testEveryOtherInstanceIsLeftOff()

	/**
	 * Running twice gives the same result as running once.
	 *
	 * @return void
	 */
	public function testRunningTwiceChangesNothingMore(): void {
		$this->store = ['integration.bag.mode' => 'live'];

		$this->step()->run($this->createMock(IOutput::class));
		$once = $this->store;
		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame($once, $this->store);
	}//end testRunningTwiceChangesNothingMore()

	/**
	 * An administrator who switched the check off is not overruled by the next upgrade.
	 *
	 * @return void
	 */
	public function testAnExistingChoiceIsKept(): void {
		$this->store = ['integration.bag.mode' => 'live', 'integration.bag.check' => 'off'];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame('off', $this->store['integration.bag.check']);
	}//end testAnExistingChoiceIsKept()

	/**
	 * A failed write does not abort the upgrade.
	 *
	 * @return void
	 */
	public function testAFailedWriteDoesNotThrow(): void {
		$this->store = ['integration.bag.source' => 'pdok'];
		$this->writeThrows = true;

		$this->step()->run($this->createMock(IOutput::class));

		self::assertArrayNotHasKey('integration.bag.check', $this->store);
	}//end testAFailedWriteDoesNotThrow()
}//end class
