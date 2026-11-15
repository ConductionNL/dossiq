<?php

/**
 * Unit tests for BrcCrossRegisterSync.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the Besluiten API keeps in step in the Zaken and Documenten registers.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync
 * @uses \OCA\Dossiq\Service\Zgw\ZgwSearchScope
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class BrcCrossRegisterSyncTest extends TestCase {
	private const ZAAK = '22222222-2222-4222-8222-222222222222';

	/**
	 * The store, or null for an instance without OpenRegister.
	 *
	 * @var RecordsBrcStoreCalls|null
	 */
	private ?RecordsBrcStoreCalls $store;

	/**
	 * Mapping configuration per "api/resource".
	 *
	 * @var array<string, array>
	 */
	private array $mappings = [
		'documenten/objectinformatieobjecten' => ['sourceRegister' => '7', 'sourceSchema' => '72'],
		'zaken/zaken' => ['sourceRegister' => '8', 'sourceSchema' => '80'],
	];

	/**
	 * Every log line, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RecordsBrcStoreCalls();
	}//end setUp()

	/**
	 * The sync under test.
	 *
	 * @param string $caseDecisionSchema The schema of a zaakbesluit.
	 *
	 * @return BrcCrossRegisterSync
	 */
	private function sync(string $caseDecisionSchema = '81'): BrcCrossRegisterSync {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = $level . ': ' . $message;
				}
			);
		}

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturnCallback(fn (): ?object => $this->store);
		$zgw->method('getLogger')->willReturn($logger);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			fn (string $zgwApi, string $resource): ?array => ($this->mappings[$zgwApi . '/' . $resource] ?? null)
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['case_decision_schema' => $caseDecisionSchema][$key] ?? '')
		);

		return new BrcCrossRegisterSync(zgwService: $zgw, settingsService: $settings);
	}//end sync()

	/**
	 * Brc-006: the zaakbesluit names the zaak by uuid and the besluit by URL, in the Zaken register.
	 *
	 * @return void
	 */
	public function testAZaakbesluitIsStoredInTheZakenRegister(): void {
		$this->sync()->syncCaseDecisionToZrc(caseUrl: 'https://nc.example.com/zaken/v1/zaken/' . self::ZAAK, decisionUrl: 'b-url');

		$this->assertSame([['8', '81', ['case' => self::ZAAK, 'decision' => 'b-url']]], $this->store->callsOf('saveObject'));
		$this->assertSame(['info: brc-006: Created zaakbesluit for zaak=' . self::ZAAK . ' besluit=b-url'], $this->log);
	}//end testAZaakbesluitIsStoredInTheZakenRegister()

	/**
	 * Brc-006 stores nothing without a schema, a uuid in the zaak URL, or a Zaken register, and
	 * logs a store that refuses.
	 *
	 * @return void
	 */
	public function testAZaakbesluitThatCannotBeStoredIsSkipped(): void {
		$zaak = 'https://nc.example.com/zaken/v1/zaken/' . self::ZAAK;

		$this->sync(caseDecisionSchema: '')->syncCaseDecisionToZrc(caseUrl: $zaak, decisionUrl: 'b-url');
		$this->sync()->syncCaseDecisionToZrc(caseUrl: 'https://nc.example.com/zaken/v1/zaken/geen', decisionUrl: 'b-url');
		unset($this->mappings['zaken/zaken']);
		$this->sync()->syncCaseDecisionToZrc(caseUrl: $zaak, decisionUrl: 'b-url');
		$this->mappings['zaken/zaken'] = ['sourceSchema' => '80'];
		$this->sync()->syncCaseDecisionToZrc(caseUrl: $zaak, decisionUrl: 'b-url');
		$this->assertSame([], $this->store->calls);

		$this->mappings['zaken/zaken'] = ['sourceRegister' => '8'];
		$this->store->failOn = ['saveObject'];
		$this->sync()->syncCaseDecisionToZrc(caseUrl: $zaak, decisionUrl: 'b-url');
		$this->store = null;
		$this->sync()->syncCaseDecisionToZrc(caseUrl: $zaak, decisionUrl: 'b-url');

		$this->assertSame(
			[
				'debug: brc-006: case_decision_schema not configured, skipping zaakbesluit sync',
				'error: brc-006: the besluit was stored, but its zaakbesluit was not: saveObject is down',
			],
			$this->log
		);
	}//end testAZaakbesluitThatCannotBeStoredIsSkipped()

	/**
	 * Brc-005a: the mirror of a document link is an objectinformatieobject of type decision.
	 *
	 * @return void
	 */
	public function testADocumentLinkIsMirrored(): void {
		$this->sync()->createOioInDrc(decisionUrl: 'b-url', ioUrl: 'io-url');
		$this->assertSame([['7', '72', ['document' => 'io-url', 'object' => 'b-url', 'objectType' => 'decision']]], $this->store->callsOf('saveObject'));

		$this->store->failOn = ['saveObject'];
		$this->sync()->createOioInDrc(decisionUrl: 'b-url', ioUrl: 'io-url');
		$this->assertSame(['error: brc-005a: the besluitinformatieobject was stored, but its objectinformatieobject in the DRC was not: saveObject is down'], $this->log);

		$this->store = new RecordsBrcStoreCalls();
		unset($this->mappings['documenten/objectinformatieobjecten']);
		$this->sync()->createOioInDrc(decisionUrl: 'b-url', ioUrl: 'io-url');
		$this->assertSame([], $this->store->calls);
		$this->store = null;
		$this->sync()->createOioInDrc(decisionUrl: 'b-url', ioUrl: 'io-url');
	}//end testADocumentLinkIsMirrored()

	/**
	 * Brc-005b and brc-009: the mirrors are found by besluit, or by besluit and document, and
	 * deleted outside the caller's own access.
	 *
	 * @return void
	 */
	public function testMirrorsAreDeletedByBesluitOrByPair(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'oio-2']];
			}//end jsonSerialize()
		};
		$this->store->searchResult = ['results' => [['id' => 'oio-1'], $entity, ['id' => ''], 'no row']];

		$this->sync()->deleteOiosForDecision(decisionUrl: 'b-url');
		$this->sync()->deleteOioByDecisionAndIo(decisionUrl: 'b-url', ioUrl: 'io-url');

		$this->assertSame(
			[
				[['params' => ['object' => 'b-url'], 'register' => 7, 'schema' => 72]],
				[['params' => ['object' => 'b-url', 'document' => 'io-url'], 'register' => 7, 'schema' => 72]],
			],
			$this->store->callsOf('search')
		);
		$this->assertSame(
			[['oio-1', false, false], ['oio-2', false, false], ['oio-1', false, false], ['oio-2', false, false]],
			$this->store->callsOf('deleteObject')
		);
		$this->assertSame([], $this->log);
	}//end testMirrorsAreDeletedByBesluitOrByPair()

	/**
	 * A mapping that cannot be searched names the orphans it leaves; a failed search is logged;
	 * no mapping or no store does nothing.
	 *
	 * @return void
	 */
	public function testMirrorsThatCannotBeFoundAreNamed(): void {
		$this->mappings['documenten/objectinformatieobjecten'] = ['sourceRegister' => 'documents', 'sourceSchema' => '72'];
		$this->sync()->deleteOiosForDecision(decisionUrl: 'b-url');
		$this->sync()->deleteOioByDecisionAndIo(decisionUrl: 'b-url', ioUrl: 'io-url');
		$this->assertSame([], $this->store->calls);

		$this->mappings['documenten/objectinformatieobjecten'] = ['sourceRegister' => '7', 'sourceSchema' => '72'];
		$this->store->failOn = ['search'];
		$this->sync()->deleteOiosForDecision(decisionUrl: 'b-url');
		$this->sync()->deleteOioByDecisionAndIo(decisionUrl: 'b-url', ioUrl: 'io-url');

		$this->assertSame(
			[
				'error: brc-005b: objectinformatieobject mapping has no searchable register/schema, so the OIOs of b-url are being left behind as orphans',
				'error: brc-005b: objectinformatieobject mapping has no searchable register/schema, so the OIO for io-url is being left behind as an orphan',
				'error: brc-009: the objectinformatieobjecten of a besluit were not all deleted from the DRC: search is down',
				'error: brc-005b: the besluitinformatieobject was deleted, but its objectinformatieobject in the DRC was not: search is down',
			],
			$this->log
		);

		$this->log = [];
		unset($this->mappings['documenten/objectinformatieobjecten']);
		$this->sync()->deleteOiosForDecision(decisionUrl: 'b-url');
		$this->sync()->deleteOioByDecisionAndIo(decisionUrl: 'b-url', ioUrl: 'io-url');
		$this->store = null;
		$this->sync()->deleteOiosForDecision(decisionUrl: 'b-url');
		$this->sync()->deleteOioByDecisionAndIo(decisionUrl: 'b-url', ioUrl: 'io-url');
		$this->assertSame([], $this->log);
	}//end testMirrorsThatCannotBeFoundAreNamed()
}//end class
