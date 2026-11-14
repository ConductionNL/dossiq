<?php

/**
 * The typed relation rows OpenRegister reports for a case, as labelled entries.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationLabels;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Which rows become entries, what each entry carries, and where its note comes from.
 *
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationLabels
 * @uses \OCA\Dossiq\Service\Relation\RelationLabelRun
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationCodec
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationStore
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class CaseRelationLabelsTest extends TestCase {

	private function store(FakeObjectService $objects): CaseRelationStore {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '5', 'case_schema' => '9'][$key] ?? $default)
		);
		$config->method('hasKey')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		$logger = $this->createMock(LoggerInterface::class);

		return new CaseRelationStore(new SettingsService($config, $apps, $container, $logger), $logger);
	}

	private function relation(string $type, string $property = 'followUpCases'): array {
		return ['type' => $type, 'property' => $property, 'direction' => 'out', 'label' => 'Vervolg', 'inverseLabel' => 'Voorganger', 'displayLabel' => 'Vervolgzaak'];
	}

	public function testOutgoingAndIncomingTypedRowsBecomeEntries(): void {
		$objects = new FakeObjectService(
			uses: ['c-1' => ['results' => [['id' => 'c-2', 'title' => 'Bouw', 'relation' => $this->relation('vervolg')]]]],
			usedBy: ['c-1' => ['results' => [['uuid' => 'c-3', 'relation' => $this->relation('subject', 'subjectCases')]]]],
		);

		$rows = (new CaseRelationLabels($this->store($objects), new CaseRelationCodec()))->rowsFor('c-1', []);

		$this->assertSame(
			[
				'c-2|vervolg' => ['caseId' => 'c-2', 'aardRelatie' => 'vervolg', 'title' => 'Bouw', 'direction' => 'out', 'label' => 'Vervolg', 'inverseLabel' => 'Voorganger', 'displayLabel' => 'Vervolgzaak', 'legacy' => false],
				'c-3|subject' => ['caseId' => 'c-3', 'aardRelatie' => 'subject', 'title' => '', 'direction' => 'out', 'label' => 'Vervolg', 'inverseLabel' => 'Voorganger', 'displayLabel' => 'Vervolgzaak', 'legacy' => false],
			],
			$rows
		);
		$this->assertSame(['getObjectUses', 'c-1'], $objects->calls[2]);
		$this->assertSame(['getObjectUsedBy', 'c-1'], $objects->calls[5]);
	}

	public function testUntypedOrIncompleteRowsAreLeftOut(): void {
		$objects = new FakeObjectService(
			uses: ['c-1' => ['results' => [
				['id' => 'c-2'],
				['id' => 'c-3', 'relation' => $this->relation('vervolg', 'parentCase')],
				['id' => 'c-4', 'relation' => 'vervolg'],
				['relation' => $this->relation('vervolg')],
				['id' => 'c-5', 'relation' => $this->relation('')],
			]]],
		);

		$this->assertSame([], (new CaseRelationLabels($this->store($objects), new CaseRelationCodec()))->rowsFor('c-1', []));
	}

	public function testTheNoteComesFromThisCaseFirstThenFromTheOtherCase(): void {
		$other = ['id' => 'c-2', 'relation' => $this->relation('vervolg'), 'relatedCases' => [['caseId' => 'c-1', 'aardRelatie' => 'vervolg', 'notes' => 'their note']]];
		$objects = new FakeObjectService(uses: ['c-1' => ['results' => [$other]]]);
		$labels  = new CaseRelationLabels($this->store($objects), new CaseRelationCodec());

		$this->assertSame('our note', $labels->rowsFor('c-1', [['caseId' => 'c-2', 'aardRelatie' => 'vervolg', 'notes' => 'our note']])['c-2|vervolg']['notes']);
		$this->assertSame('their note', $labels->rowsFor('c-1', [['caseId' => 'c-2', 'aardRelatie' => 'vervolg', 'notes' => '']])['c-2|vervolg']['notes']);

		$bare = new FakeObjectService(uses: ['c-1' => ['results' => [['id' => 'c-2', 'relation' => $this->relation('vervolg')]]]]);
		$this->assertArrayNotHasKey('notes', (new CaseRelationLabels($this->store($bare), new CaseRelationCodec()))->rowsFor('c-1', [])['c-2|vervolg']);
	}
}
