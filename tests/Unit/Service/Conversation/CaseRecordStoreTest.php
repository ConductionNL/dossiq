<?php

/**
 * Tests for the case record store.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Conversation
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
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Conversation;


use DateTimeImmutable;
use OCA\Dossiq\Service\Conversation\CaseRecordStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store reads a case and writes only the fields it is given.
 *
 * @covers \OCA\Dossiq\Service\Conversation\CaseRecordStore
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseRecordStoreTest extends TestCase {

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The configuration the store reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'case_schema' => 'case'];

	/**
	 * Seed one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Kapvergunning', 'identifier' => 'ZAAK-001', 'status' => 'open'],
		);
	}//end setUp()

	/**
	 * The store under test, over the given object service.
	 *
	 * @param object|null $objectService The object service, null for none.
	 *
	 * @return CaseRecordStore
	 */
	private function store(?object $objectService): CaseRecordStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new CaseRecordStore(settingsService: $settings);
	}//end store()

	/**
	 * A stored case reads back as stored.
	 *
	 * @return void
	 */
	public function testReadAnswersTheStoredCase(): void {
		$this->assertSame(
			['title' => 'Kapvergunning', 'identifier' => 'ZAAK-001', 'status' => 'open', 'id' => 'case-1'],
			$this->store(objectService: $this->register)->read(caseId: 'case-1')
		);
	}//end testReadAnswersTheStoredCase()

	/**
	 * An unknown case, no OpenRegister, or no case schema all read as null.
	 *
	 * @return void
	 */
	public function testReadAnswersNullWhenTheCaseCannotBeRead(): void {
		$this->assertNull($this->store(objectService: $this->register)->read(caseId: 'missing'));
		$this->assertNull($this->store(objectService: null)->read(caseId: 'case-1'));

		$this->config['case_schema'] = '';
		$this->assertNull($this->store(objectService: $this->register)->read(caseId: 'case-1'));
	}//end testReadAnswersNullWhenTheCaseCannotBeRead()

	/**
	 * A write sends only the changed fields to a service that patches.
	 *
	 * @return void
	 */
	public function testWritePatchesOnlyTheGivenFields(): void {
		$service = new class {
			/**
			 * The patch calls received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Record a patch and answer the patched object.
			 *
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				$this->calls[] = ['objectId' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema];

				return ['id' => $objectId] + $data;
			}
		};

		$result = $this->store(objectService: $service)->write(caseId: 'case-1', changes: ['conversations' => []]);

		$this->assertSame(['id' => 'case-1', 'conversations' => []], $result);
		$this->assertSame(
			[['objectId' => 'case-1', 'data' => ['conversations' => []], 'register' => 'dossiq', 'schema' => 'case']],
			$service->calls
		);
	}//end testWritePatchesOnlyTheGivenFields()

	/**
	 * Without a patch seam, a write merges the changes over the stored case.
	 *
	 * @return void
	 */
	public function testWriteMergesOverTheStoredCase(): void {
		$result = $this->store(objectService: $this->register)->write(caseId: 'case-1', changes: ['status' => 'closed']);

		$this->assertSame('closed', $result['status']);
		$this->assertSame('Kapvergunning', $result['title']);
		$this->assertSame(
			['title' => 'Kapvergunning', 'identifier' => 'ZAAK-001', 'status' => 'closed', 'id' => 'case-1'],
			$this->register->row(schema: 'case', uuid: 'case-1')
		);
	}//end testWriteMergesOverTheStoredCase()

	/**
	 * A write without OpenRegister is refused.
	 *
	 * @return void
	 */
	public function testWriteWithoutOpenRegisterThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->store(objectService: null)->write(caseId: 'case-1', changes: ['status' => 'closed']);
	}//end testWriteWithoutOpenRegisterThrows()

	/**
	 * A write without a configured register is refused, and nothing is stored.
	 *
	 * @return void
	 */
	public function testWriteWithoutConfigurationThrows(): void {
		$this->config['register'] = '';

		try {
			$this->store(objectService: $this->register)->write(caseId: 'case-1', changes: ['status' => 'closed']);
			$this->fail('A write without a register was accepted.');
		} catch (RuntimeException $e) {
			$this->assertSame('Case schema not configured', $e->getMessage());
		}

		$this->assertSame('open', $this->register->row(schema: 'case', uuid: 'case-1')['status']);
	}//end testWriteWithoutConfigurationThrows()

	/**
	 * Only array records count as conversations, re-indexed in order.
	 *
	 * @return void
	 */
	public function testConversationsOfKeepsOnlyRecords(): void {
		$store = $this->store(objectService: $this->register);

		$this->assertSame([], $store->conversationsOf(case: []));
		$this->assertSame([], $store->conversationsOf(case: ['conversations' => 'room-1']));
		$this->assertSame(
			[['roomId' => 'a'], ['roomId' => 'b']],
			$store->conversationsOf(case: ['conversations' => [3 => ['roomId' => 'a'], 4 => 'junk', 7 => ['roomId' => 'b']]])
		);
	}//end testConversationsOfKeepsOnlyRecords()

	/**
	 * A room is named after the identifier, then the title, then only the fallback.
	 *
	 * @return void
	 */
	public function testRoomNameForPrefersIdentifierThenTitle(): void {
		$store = $this->store(objectService: $this->register);

		$this->assertSame('Zaak ZAAK-001', $store->roomNameFor(case: ['identifier' => 'ZAAK-001', 'title' => 'Kap'], fallback: 'Zaak'));
		$this->assertSame('Zaak Kap', $store->roomNameFor(case: ['title' => 'Kap'], fallback: 'Zaak'));
		$this->assertSame('Zaak', $store->roomNameFor(case: [], fallback: 'Zaak'));
	}//end testRoomNameForPrefersIdentifierThenTitle()

	/**
	 * A case type reference is read as a string, or as the id or uuid of an object.
	 *
	 * @return void
	 */
	public function testCaseTypeIdOfReadsStringsAndObjects(): void {
		$store = $this->store(objectService: $this->register);

		$this->assertSame('type-1', $store->caseTypeIdOf(case: ['caseType' => 'type-1']));
		$this->assertSame('type-2', $store->caseTypeIdOf(case: ['caseType' => ['id' => 'type-2', 'uuid' => 'u']]));
		$this->assertSame('u-3', $store->caseTypeIdOf(case: ['caseType' => ['uuid' => 'u-3']]));
		$this->assertSame('', $store->caseTypeIdOf(case: ['caseType' => []]));
		$this->assertSame('', $store->caseTypeIdOf(case: []));
	}//end testCaseTypeIdOfReadsStringsAndObjects()

	/**
	 * The current moment is an ATOM timestamp of now.
	 *
	 * @return void
	 */
	public function testNowIsAnAtomTimestamp(): void {
		$now = $this->store(objectService: $this->register)->now();

		$parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $now);
		$this->assertInstanceOf(DateTimeImmutable::class, $parsed);
		$this->assertSame($now, $parsed->format(DateTimeImmutable::ATOM));
		$this->assertLessThan(60, abs(time() - $parsed->getTimestamp()));
	}//end testNowIsAnAtomTimestamp()

}//end class
