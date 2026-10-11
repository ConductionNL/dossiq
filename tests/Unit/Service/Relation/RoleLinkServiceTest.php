<?php

/**
 * A case is linked with a role its case type declares.
 *
 * Neutral fixture case type: `fixture-review`, with one role `reviews` that
 * points at an object in `decision_schema` and opens a `review_schema` record.
 * The store is a real in-memory register, so a record that drops a field or
 * lands twice is visible. The relation write itself is CaseRelationService's,
 * which has its own suite; here its contract is asserted: which two cases, and
 * which relation type.
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
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Relation\RoleLinkDefinition;
use OCA\Dossiq\Service\Relation\RoleLinkService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkService
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkDefinition
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkDefinitionReader
 */
class RoleLinkServiceTest extends TestCase {

	/**
	 * The fixture role, as a case type stores it.
	 *
	 * @var array<string, mixed>
	 */
	private const REVIEWS = [
		'role'               => 'reviews',
		'label'              => 'Reviewed decision',
		'target'             => 'object',
		'targetSchema'       => 'decision_schema',
		'requiredAtIntake'   => true,
		'record'             => [
			'schema'      => 'review_schema',
			'caseField'   => 'case',
			'targetField' => 'reviewedDecision',
			'actorField'  => 'registeredBy',
		],
	];

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The relation writer.
	 *
	 * @var CaseRelationService&MockObject
	 */
	private CaseRelationService $relations;

	/**
	 * The service under test.
	 *
	 * @var RoleLinkService
	 */
	private RoleLinkService $service;

	/**
	 * Seed the fixture case type, two cases and a decision of the earlier one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'fixture-review', 'relations' => [self::REVIEWS]]);
		$this->store->seed(schema: 'case', uuid: 'case-c', row: ['title' => 'Later case', 'caseType' => 'ct-1']);
		$this->store->seed(schema: 'case', uuid: 'case-p', row: ['title' => 'Earlier case', 'caseType' => 'ct-other']);
		$this->store->seed(schema: 'decision', uuid: 'dec-d', row: ['case' => 'case-p']);
		$this->store->seed(schema: 'decision', uuid: 'dec-orphan', row: ['title' => 'No case']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register'         => 'dossiq',
				'case_schema'      => 'case',
				'case_type_schema' => 'caseType',
				'decision_schema'  => 'decision',
				'review_schema'    => 'review',
				default            => '',
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->relations = $this->createMock(CaseRelationService::class);
		$this->service   = new RoleLinkService(
			settingsService: $settings,
			relations: $this->relations,
			userSession: $session,
		);
	}//end setUp()

	/**
	 * The case of the decision is linked, and the record holds what the server resolved.
	 *
	 * @return void
	 */
	public function testTheCaseOfTheNamedObjectIsLinkedAndTheRecordOpened(): void {
		$this->relations->expects($this->once())
			->method('addRelation')
			->with(caseId: 'case-c', targetId: 'case-p', natureRelationship: 'vervolg')
			->willReturn(['ok' => true]);

		$result = $this->service->link(
			caseId: 'case-c',
			role: 'reviews',
			targetId: 'dec-d',
			payload: ['grounds' => 'Too strict', 'case' => 'case-forged', 'registeredBy' => 'mallory'],
		);

		self::assertSame('case-p', $result['linkedCase']);
		$records = array_values($this->store->all(schema: 'review'));
		self::assertCount(1, $records);
		self::assertSame('case-c', $records[0]['case']);
		self::assertSame('dec-d', $records[0]['reviewedDecision']);
		self::assertSame('handler-1', $records[0]['registeredBy']);
		self::assertSame('Too strict', $records[0]['grounds']);
	}//end testTheCaseOfTheNamedObjectIsLinkedAndTheRecordOpened()

	/**
	 * Linking twice keeps one record and accepts the duplicate relation.
	 *
	 * @return void
	 */
	public function testLinkingTwiceWritesOneRecord(): void {
		$this->relations->method('addRelation')->willReturnOnConsecutiveCalls(['ok' => true], ['ok' => false, 'reason' => 'duplicate']);

		$this->service->link(caseId: 'case-c', role: 'reviews', targetId: 'dec-d');
		$second = $this->service->link(caseId: 'case-c', role: 'reviews', targetId: 'dec-d');

		self::assertCount(1, $this->store->all(schema: 'review'));
		self::assertSame('case-c', $second['record']['case']);
	}//end testLinkingTwiceWritesOneRecord()

	/**
	 * A refused relation is not reported as a link.
	 *
	 * @return void
	 */
	public function testARefusedRelationRefusesTheLink(): void {
		$this->relations->method('addRelation')->willReturn(['ok' => false, 'reason' => 'access_denied']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('access_denied');
		$this->service->link(caseId: 'case-c', role: 'reviews', targetId: 'dec-d');
	}//end testARefusedRelationRefusesTheLink()

	/**
	 * An object that belongs to no case still opens the record, and links no case.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutACaseLinksNoCase(): void {
		$this->relations->expects($this->never())->method('addRelation');

		$result = $this->service->link(caseId: 'case-c', role: 'reviews', targetId: 'dec-orphan');

		self::assertSame('', $result['linkedCase']);
		self::assertCount(1, $this->store->all(schema: 'review'));
	}//end testAnObjectWithoutACaseLinksNoCase()

	/**
	 * A role the case type does not declare is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUndeclaredRoleIsRefused(): void {
		$this->relations->expects($this->never())->method('addRelation');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("no relation role 'appeals'");
		$this->service->link(caseId: 'case-c', role: 'appeals', targetId: 'dec-d');
	}//end testAnUndeclaredRoleIsRefused()

	/**
	 * A target that does not exist is refused.
	 *
	 * @return void
	 */
	public function testAMissingTargetIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->link(caseId: 'case-c', role: 'reviews', targetId: 'dec-missing');
	}//end testAMissingTargetIsRefused()

	/**
	 * A required role is missing until its record exists.
	 *
	 * @return void
	 */
	public function testARequiredRoleIsMissingUntilLinked(): void {
		$this->relations->method('addRelation')->willReturn(['ok' => true]);

		$before = $this->service->rolesFor(caseId: 'case-c');
		self::assertSame(['reviews'], $before['missing']);
		self::assertFalse($before['roles'][0]['linked']);

		$this->service->link(caseId: 'case-c', role: 'reviews', targetId: 'dec-d');

		$after = $this->service->rolesFor(caseId: 'case-c');
		self::assertSame([], $after['missing']);
		self::assertTrue($after['roles'][0]['linked']);
	}//end testARequiredRoleIsMissingUntilLinked()

	/**
	 * The fixture role is accepted by the real caseType schema, so the role reaches the store.
	 *
	 * @return void
	 */
	public function testTheRoleFitsTheRealCaseTypeSchema(): void {
		$errors = (new RealSchemaValidator())->errors(slug: 'caseType', payload: ['relations' => [self::REVIEWS]], creating: false);

		self::assertSame([], $errors);
		self::assertSame('decision_schema', (new RoleLinkDefinition(config: self::REVIEWS))->targetSchema);
	}//end testTheRoleFitsTheRealCaseTypeSchema()
}//end class
