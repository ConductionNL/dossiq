<?php

/**
 * Unit tests for DatasetLoad.
 *
 * The Example data step's load: an unknown dataset refused, no pick refused,
 * "none" recorded as a decline, an import recorded with its counts, and a
 * failed import leaving the step open.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Controller\Support\DatasetLoad
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\DatasetLoad;
use OCA\Dossiq\Kernel\Contracts\CaseTypePackageChoiceInterface;
use OCA\Dossiq\Kernel\Contracts\DemoDataServiceInterface;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatasetLoadTest extends TestCase {

	/**
	 * The app config written during a test, by key.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * A loader over an app config that records its writes and holds `$stored`.
	 *
	 * @param array<string, string>      $stored   The app config before the load.
	 * @param array<string, mixed>|null  $imported What the import returns; null makes it throw.
	 *
	 * @return DatasetLoad
	 */
	private function loader(array $stored = [], ?array $imported = null): DatasetLoad {
		$this->written = [];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		$demo = $this->createMock(originalClassName: DemoDataServiceInterface::class);
		$demo->method('listChoices')->willReturn([['id' => 'none'], ['id' => 'demo']]);
		if ($imported === null) {
			$demo->method('install')->willThrowException(exception: new RuntimeException('the import stored nothing'));
		} else {
			$demo->method('install')->willReturn($imported);
		}

		$caseTypes = $this->createMock(originalClassName: CaseTypePackageChoiceInterface::class);
		$caseTypes->method('writeExamplesOfInstalled')->willReturn(['zaak' => 'written']);

		return new DatasetLoad(appConfig: $appConfig, demoDataService: $demo, caseTypes: $caseTypes);
	}//end loader()

	/**
	 * A posted dataset nobody offers is refused, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testAnUnknownDatasetIsRefusedAndNothingIsRecorded(): void {
		$outcome = $this->loader()->load(actionId: 'load-demo-data', posted: 'other');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $outcome->getStatus());
		self::assertSame(expected: 'No dataset is called "other".', actual: $outcome->getData()['message']);
		self::assertSame(expected: [], actual: $this->written);
	}//end testAnUnknownDatasetIsRefusedAndNothingIsRecorded()

	/**
	 * No pick is refused, but the legacy action means the shipped set.
	 *
	 * @return void
	 */
	public function testNoPickIsRefusedButTheLegacyActionImportsTheShippedSet(): void {
		$refused = $this->loader()->load(actionId: 'load-demo-data', posted: null);
		self::assertSame(expected: ['success' => false, 'message' => 'Pick a dataset first.'], actual: $refused->getData());

		$counts = ['objects' => 5, 'requested' => 6, 'refused' => 1, 'unchanged' => 0];
		$loaded = $this->loader(imported: $counts)->load(actionId: 'install-demo-data', posted: null);

		self::assertTrue(condition: $loaded->getData()['success']);
		self::assertSame(
			expected: 'Demo data installed: 5 of 6 objects stored, 1 refused, 0 already present.',
			actual: $loaded->getData()['message']
		);
		self::assertSame(expected: ['zaak' => 'written'], actual: $loaded->getData()['detail']['packageExamples']);
		self::assertSame(expected: ['demo_dataset' => 'demo', 'demo_data_decided' => 'installed'], actual: $this->written);
	}//end testNoPickIsRefusedButTheLegacyActionImportsTheShippedSet()

	/**
	 * "None", picked or declined, records the decline; a failed import records nothing.
	 *
	 * @return void
	 */
	public function testNoneIsADeclineAndAFailedImportRecordsNothing(): void {
		$declined = $this->loader(stored: ['demo_dataset' => 'none'])->load(actionId: 'load-demo-data', posted: null);
		self::assertSame(expected: ['success' => true, 'message' => 'No example data was loaded.'], actual: $declined->getData());
		self::assertSame(expected: ['demo_dataset' => 'none', 'demo_data_decided' => 'skipped'], actual: $this->written);

		$failed = $this->loader()->load(actionId: 'load-demo-data', posted: 'demo');
		self::assertSame(expected: ['success' => false, 'message' => 'the import stored nothing'], actual: $failed->getData());
		self::assertSame(expected: [], actual: $this->written);
	}//end testNoneIsADeclineAndAFailedImportRecordsNothing()
}//end class
