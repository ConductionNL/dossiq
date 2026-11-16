<?php

/**
 * Sociaal Domein Store Test
 *
 * Pins the social domain register store: which open case contacts a person
 * has per domain, how a case type category maps to a domain, and that every
 * read or write the register cannot answer is refused as indeterminate rather
 * than answered empty.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\SociaalDomein
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCA\Dossiq\Service\Support\ObjectSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for SociaalDomeinStore.
 */
#[CoversClass(SociaalDomeinStore::class)]
#[UsesClass(RefusedException::class)]
#[UsesClass(ObjectSearch::class)]
final class SociaalDomeinStoreTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * Set up an empty register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new FakeSociaalDomeinObjects();
	}//end setUp()

	/**
	 * The store over the in-memory register, or over no register at all.
	 *
	 * @param bool                 $reachable Whether the object service is there.
	 * @param LoggerInterface|null $logger    The logger, a null logger when omitted.
	 *
	 * @return SociaalDomeinStore
	 */
	private function store(bool $reachable = true, ?LoggerInterface $logger = null): SociaalDomeinStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($reachable === true) ? $this->objects : null);

		return new SociaalDomeinStore(settingsService: $settings, logger: ($logger ?? new NullLogger()));
	}//end store()

	/**
	 * Assert that a call is refused as indeterminate under a rule.
	 *
	 * @param string   $rule The expected rule.
	 * @param callable $call The call.
	 *
	 * @return void
	 */
	private static function assertRefused(string $rule, callable $call): void {
		try {
			$call();
		} catch (RefusedException $e) {
			self::assertSame($rule, $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			return;
		}

		self::fail('Expected a refusal under ' . $rule);
	}//end assertRefused()

	/**
	 * Each domain is reached from each of its categories; anything else is no domain.
	 *
	 * @return void
	 */
	public function testDomainOfCategory(): void {
		$store = $this->store();

		self::assertSame('Jeugdwet', $store->domainOfCategory(category: 'Jeugdhulp'));
		self::assertSame('Jeugdwet', $store->domainOfCategory(category: 'Jeugd'));
		self::assertSame('Wmo', $store->domainOfCategory(category: 'Wmo'));
		self::assertSame('Participatiewet', $store->domainOfCategory(category: 'Werk en inkomen'));
		self::assertSame('', $store->domainOfCategory(category: 'Vergunningen'));
		self::assertSame('', $store->domainOfCategory(category: 'wmo'));
	}//end testDomainOfCategory()

	/**
	 * One contact per domain: the assignee, else the group, else the domain name;
	 * final cases and cases of no social domain are skipped.
	 *
	 * @return void
	 */
	public function testOpenCaseContactsAboutPicksOneContactPerDomain(): void {
		$this->objects->seed(schema: 'caseType', row: ['id' => 'ct-jeugd', 'category' => ' Jeugdhulp ']);
		$this->objects->seed(schema: 'caseType', row: ['id' => 'ct-wmo', 'category' => 'Wmo']);
		$this->objects->seed(schema: 'caseType', row: ['id' => 'ct-part', 'category' => 'Participatiewet']);
		$this->objects->seed(schema: 'caseType', row: ['id' => 'ct-other', 'category' => 'Vergunningen']);

		$bsn = '999993653';
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => $bsn, 'caseType' => 'ct-jeugd', 'isFinalStatus' => true, 'assignee' => 'closed-handler']);
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => $bsn, 'caseType' => 'ct-jeugd', 'assignee' => '  ', 'assignedGroup' => 'Wijkteam Noord']);
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => $bsn, 'caseType' => 'ct-jeugd', 'assignee' => 'second-handler']);
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => $bsn, 'caseType' => 'ct-wmo', 'assignee' => 'anna', 'assignedGroup' => 'Team Wmo']);
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => $bsn, 'caseType' => 'ct-part']);
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => $bsn, 'caseType' => 'ct-other', 'assignee' => 'vera']);
		$this->objects->seed(schema: 'case', row: ['subjectBsn' => '111222333', 'caseType' => 'ct-wmo', 'assignee' => 'someone-else']);

		$contacts = $this->store()->openCaseContactsAbout(bsn: $bsn);

		self::assertSame(
			['Jeugdwet' => 'Wijkteam Noord', 'Wmo' => 'anna', 'Participatiewet' => 'Participatiewet'],
			$contacts
		);
		self::assertFalse($this->objects->scopes[0]['rbac'], 'the cross-domain read runs unscoped');
	}//end testOpenCaseContactsAboutPicksOneContactPerDomain()

	/**
	 * A register that cannot be read refuses the contacts rather than answering "none".
	 *
	 * @return void
	 */
	public function testOpenCaseContactsAboutRefusesWhenUnreadable(): void {
		$this->objects->broken = true;
		$store = $this->store();

		self::assertRefused(rule: 'sociaal-domein-unreadable', call: static fn () => $store->openCaseContactsAbout(bsn: '999993653'));
	}//end testOpenCaseContactsAboutRefusesWhenUnreadable()

	/**
	 * No object service at all refuses every call as unavailable.
	 *
	 * @return void
	 */
	public function testNoObjectServiceIsRefusedAsUnavailable(): void {
		$store = $this->store(reachable: false);

		self::assertRefused(rule: 'sociaal-domein-store-unavailable', call: static fn () => $store->rows(schema: 'casePlan'));
		self::assertRefused(rule: 'sociaal-domein-store-unavailable', call: static fn () => $store->read(schema: 'casePlan', id: 'x'));
		self::assertRefused(rule: 'sociaal-domein-store-unavailable', call: static fn () => $store->write(schema: 'casePlan', object: []));
	}//end testNoObjectServiceIsRefusedAsUnavailable()

	/**
	 * Rows are filtered by the given filters.
	 *
	 * @return void
	 */
	public function testRowsReturnsTheMatchingRows(): void {
		$this->objects->seed(schema: 'casePlan', row: ['id' => 'p1', 'caseId' => 'c1']);
		$this->objects->seed(schema: 'casePlan', row: ['id' => 'p2', 'caseId' => 'c2']);

		$rows = $this->store()->rows(schema: 'casePlan', filters: ['caseId' => 'c2']);

		self::assertSame([['id' => 'p2', 'caseId' => 'c2']], $rows);
	}//end testRowsReturnsTheMatchingRows()

	/**
	 * An unreadable schema is logged and refused, never answered with an empty list.
	 *
	 * @return void
	 */
	public function testRowsLogsAndRefusesWhenUnreadable(): void {
		$this->objects->brokenSchemas = ['casePlan'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				self::stringContains('casePlan could not be read'),
				['error' => 'the store is unreachable']
			);
		$store = $this->store(logger: $logger);

		self::assertRefused(rule: 'sociaal-domein-unreadable', call: static fn () => $store->rows(schema: 'casePlan'));
	}//end testRowsLogsAndRefusesWhenUnreadable()

	/**
	 * A blank id reads as nothing without touching the register; a known id reads its row.
	 *
	 * @return void
	 */
	public function testReadByIdAndBlankId(): void {
		$this->objects->broken = true;
		self::assertNull($this->store()->read(schema: 'casePlan', id: '   '));

		$this->objects->broken = false;
		$this->objects->seed(schema: 'casePlan', row: ['id' => 'p1', 'goal' => 'Wonen']);

		self::assertSame(['id' => 'p1', 'goal' => 'Wonen'], $this->store()->read(schema: 'casePlan', id: 'p1'));
		self::assertNull($this->store()->read(schema: 'casePlan', id: 'unknown'));
	}//end testReadByIdAndBlankId()

	/**
	 * A read that throws is refused.
	 *
	 * @return void
	 */
	public function testReadRefusesWhenUnreadable(): void {
		$this->objects->broken = true;
		$store = $this->store();

		self::assertRefused(rule: 'sociaal-domein-unreadable', call: static fn () => $store->read(schema: 'casePlan', id: 'p1'));
	}//end testReadRefusesWhenUnreadable()

	/**
	 * A write with a declared id updates that object; without one it creates.
	 *
	 * @return void
	 */
	public function testWriteUsesTheDeclaredIdAsUuid(): void {
		$store = $this->store();

		$updated = $store->write(schema: 'casePlan', object: ['id' => ' p9 ', 'goal' => 'Werk']);
		$created = $store->write(schema: 'casePlan', object: ['goal' => 'Schulden']);

		self::assertSame('p9', $updated['id']);
		self::assertSame('Werk', $this->objects->store['casePlan']['p9']['goal']);
		self::assertSame('casePlan-1', $created['id']);
		self::assertSame('Schulden', $created['goal']);
	}//end testWriteUsesTheDeclaredIdAsUuid()

	/**
	 * A write that throws is refused as not written.
	 *
	 * @return void
	 */
	public function testWriteRefusesWhenTheSaveThrows(): void {
		$this->objects->broken = true;
		$store = $this->store();

		self::assertRefused(rule: 'sociaal-domein-not-written', call: static fn () => $store->write(schema: 'casePlan', object: ['goal' => 'x']));
	}//end testWriteRefusesWhenTheSaveThrows()

	/**
	 * A save that answers a shape no array can be made of is refused, not read as success.
	 *
	 * @return void
	 */
	public function testWriteRefusesAnUnreadableSaveResult(): void {
		$objects = new class {
			/**
			 * A save that answers a plain scalar.
			 *
			 * @param mixed ...$args The call arguments.
			 *
			 * @return string
			 */
			public function saveObject(mixed ...$args): string {
				return 'saved';
			}//end saveObject()
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$store = new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger());

		self::assertRefused(rule: 'sociaal-domein-not-written', call: static fn () => $store->write(schema: 'casePlan', object: ['goal' => 'x']));
	}//end testWriteRefusesAnUnreadableSaveResult()
}//end class
