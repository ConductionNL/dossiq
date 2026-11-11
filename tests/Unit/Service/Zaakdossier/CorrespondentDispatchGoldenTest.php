<?php

/**
 * Golden test for CorrespondentWriter::recordDispatches: the exact lookups and
 * rows per correspondent, and what a failing store answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use OCA\Dossiq\Service\Zaakdossier\DocumentListingLabels;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CorrespondentDispatchGoldenTest extends TestCase {

	private object $objects;

	private array $config = ['register' => 'dossiq', 'dispatch_schema' => 'dispatch'];

	protected function setUp(): void {
		$this->objects = new class {
			public array $calls = [];

			public array $existing = [];

			public array $failSearch = [];

			public array $failSave = [];

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['search', $register, $schema, $filters];
				if (in_array($filters['involvedParty'], $this->failSearch, true) === true) {
					throw new RuntimeException('search down');
				}

				$key = $filters['involvedParty'] . '|' . $filters['relationshipType'];
				return in_array($key, $this->existing, true) ? [['id' => 'old']] : [];
			}

			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$this->calls[] = ['save', $register, $schema, $object];
				if (in_array($object['involvedParty'], $this->failSave, true) === true) {
					throw new RuntimeException('save down');
				}

				return ['id' => 'new'] + $object;
			}
		};
	}//end setUp()

	public function testEveryCorrespondentGetsOneLookupAndOneRow(): void {
		$this->objects->existing   = ['party-b|geadresseerde'];
		$this->objects->failSearch = ['party-c'];
		$this->objects->failSave   = ['party-d'];
		$before = date('Y-m-d');

		$written = $this->writer()->recordDispatches(
			caseId: 'case-1',
			documentId: 'doc-1',
			correspondents: ['sender' => ' party-a ', 'recipients' => ['party-a', ' ', 'party-b', 'party-c', 'party-d', 'party-e']]
		);

		$after = date('Y-m-d');
		$this->assertSame(3, $written);
		// The clock is not frozen: every row carries the day it was written,
		// which is the day at the start or at the end of the call.
		foreach ($this->objects->calls as $i => $call) {
			if ($call[0] === 'save') {
				$date = ($call[3]['receiveDate'] ?? $call[3]['sendDate']);
				$this->assertContains($date, [$before, $after]);
				$this->objects->calls[$i][3][array_key_last($call[3])] = 'today';
			}
		}

		$today = 'today';
		$lookup = static fn (string $party, string $role): array => [
			'search',
			'dossiq',
			'dispatch',
			['document' => 'doc-1', 'involvedParty' => $party, 'relationshipType' => $role, '_limit' => 1],
		];
		$row = static fn (string $party, string $role, string $date): array => [
			'save',
			'dossiq',
			'dispatch',
			['document' => 'doc-1', 'case' => 'case-1', 'involvedParty' => $party, 'relationshipType' => $role, $date => $today],
		];
		$this->assertSame(
			[
				$lookup('party-a', 'afzender'),
				$row('party-a', 'afzender', 'receiveDate'),
				$lookup('party-a', 'geadresseerde'),
				$row('party-a', 'geadresseerde', 'sendDate'),
				$lookup('party-b', 'geadresseerde'),
				$lookup('party-c', 'geadresseerde'),
				$lookup('party-d', 'geadresseerde'),
				$row('party-d', 'geadresseerde', 'sendDate'),
				$lookup('party-e', 'geadresseerde'),
				$row('party-e', 'geadresseerde', 'sendDate'),
			],
			$this->objects->calls
		);
		$this->assertSame(DocumentCorrespondents::ROLE_SENDER, 'afzender');
		$this->assertSame(DocumentCorrespondents::ROLE_RECIPIENT, 'geadresseerde');
	}//end testEveryCorrespondentGetsOneLookupAndOneRow()

	public function testNothingToRecord(): void {
		$writer = $this->writer();
		$this->assertSame(0, $writer->recordDispatches(caseId: 'c', documentId: ' ', correspondents: ['sender' => 'p']));
		$this->assertSame(0, $writer->recordDispatches(caseId: 'c', documentId: 'd', correspondents: ['sender' => ' ', 'recipients' => ['', ' ']]));
		$this->assertSame(0, $writer->recordDispatches(caseId: 'c', documentId: 'd', correspondents: []));
		$this->assertSame([], $this->objects->calls);
	}//end testNothingToRecord()

	public function testARecipientStringIsOneRecipient(): void {
		$this->assertSame(1, $this->writer()->recordDispatches(caseId: '', documentId: 'd', correspondents: ['recipients' => 'party-z']));
		$this->assertSame('party-z', $this->objects->calls[1][3]['involvedParty']);
		$this->assertSame('', $this->objects->calls[1][3]['case']);
	}//end testARecipientStringIsOneRecipient()

	public function testNoDispatchSchemaWritesNothing(): void {
		$this->config['dispatch_schema'] = '';
		$this->assertSame(0, $this->writer()->recordDispatches(caseId: 'c', documentId: 'd', correspondents: ['sender' => 'p']));
		$this->assertSame([], $this->objects->calls);
	}//end testNoDispatchSchemaWritesNothing()

	private function writer(): CorrespondentWriter {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$config = $this->config;
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new CorrespondentWriter(
			rules: new DocumentCorrespondents(),
			people: $this->createMock(originalClassName: PersonLinkReader::class),
			settingsService: $settings,
			store: new DocumentRecordStore(settingsService: $settings),
			labels: new DocumentListingLabels(settingsService: $settings),
		);
	}//end writer()
}//end class
