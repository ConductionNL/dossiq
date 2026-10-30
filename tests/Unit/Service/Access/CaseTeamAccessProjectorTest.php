<?php

/**
 * What the handling team projector writes onto the live case schema.
 *
 * The schema is a stand-in that records what it is given; the case types come
 * from a store stand-in; everything between them is the real code, including
 * the shipped block read from the fragment-merged register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\Access\CaseTeamAccessRules;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseTeamAccessProjector
 * @uses \OCA\Dossiq\Service\Access\CaseTeamAccessRules
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessProjectorTest extends TestCase {

	/**
	 * The live case schema stand-in.
	 *
	 * @var object
	 */
	private object $schema;

	/**
	 * The schema mapper stand-in, counting its writes.
	 *
	 * @var object
	 */
	private object $mapper;

	/**
	 * Build the schema and the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new class {
			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $authorization = ['roles' => ['behandelaar' => ['behandelaars']]];

			public function getAuthorization(): ?array {
				return $this->authorization;
			}

			public function setAuthorization(?array $authorization): void {
				$this->authorization = $authorization;
			}
		};

		$this->mapper = new class {
			public int $writes = 0;

			public function update(object $schema): object {
				$this->writes++;
				return $schema;
			}
		};
	}//end setUp()

	/**
	 * A projector over the given case type rows.
	 *
	 * @param array<int, array<string, mixed>> $rows       The case types the store answers.
	 * @param bool                             $hasMapper  Whether OpenRegister is installed.
	 *
	 * @return CaseTeamAccessProjector The projector.
	 */
	private function projector(array $rows, bool $hasMapper = true): CaseTeamAccessProjector {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('everyCaseType')->with(['_rbac' => false, '_multitenancy' => false])->willReturn($rows);
		$store->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));

		$slugs = $this->createMock(SchemaSlugResolver::class);
		$slugs->method('resolve')->willReturn($this->schema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($hasMapper);
		$container->method('get')->willReturn($this->mapper);

		return new CaseTeamAccessProjector(
			store: $store,
			handling: new CaseTypeHandling(),
			rules: new CaseTeamAccessRules(),
			slugs: $slugs,
			fragments: new RegisterFragmentMerger(),
			container: $container,
			logger: new NullLogger(),
		);
	}//end projector()

	/**
	 * The shipped case block closes the case to everybody but the assignee.
	 *
	 * Read from the real fragment-merged register: the roles the notification
	 * dispatcher reads stay, the assignee and the background account read and
	 * update, the staff group creates, administrators alone delete, and no
	 * entry grants `authenticated` or `public` without a match.
	 *
	 * @return void
	 */
	public function testTheShippedCaseBlockIsClosed(): void {
		$block = $this->projector(rows: [])->shippedBlock();

		$assignee = ['group' => 'authenticated', 'match' => ['assignee' => '$userId']];
		$this->assertArrayHasKey('roles', $block);
		$this->assertSame([$assignee, 'dossiq-background-service'], $block['read']);
		$this->assertSame([$assignee, 'dossiq-background-service'], $block['update']);
		$this->assertSame(['dossiq-medewerkers', 'dossiq-background-service'], $block['create']);
		$this->assertSame(['admin'], $block['delete']);

		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$this->assertNotContains('authenticated', $block[$action], $action . ' grants every signed in account');
			$this->assertNotContains('public', $block[$action], $action . ' grants anonymous callers');
		}
	}//end testTheShippedCaseBlockIsClosed()

	/**
	 * A case type's handling teams reach the live schema.
	 *
	 * The default group and the declared teams both count, through the one
	 * reader `CaseTypeHandling::teams()`.
	 *
	 * @return void
	 */
	public function testACaseTypesHandlingTeamsReachTheLiveSchema(): void {
		$written = $this->projector(
			rows: [
				['id' => 'ct-permit', 'handling' => ['defaultGroup' => 'vergunningen', 'teams' => ['handhaving']]],
				['id' => 'ct-woo', 'handling' => ['teams' => ['woo']]],
			]
		)->reapply();

		$this->assertTrue($written);
		$this->assertSame(1, $this->mapper->writes);

		$read = $this->schema->authorization['read'];
		$this->assertContains(['group' => 'vergunningen', 'match' => ['caseType' => ['$in' => ['ct-permit']]]], $read);
		$this->assertContains(['group' => 'handhaving', 'match' => ['caseType' => ['$in' => ['ct-permit']]]], $read);
		$this->assertContains(['group' => 'woo', 'match' => ['caseType' => ['$in' => ['ct-woo']]]], $read);
		$this->assertSame(['admin'], $this->schema->authorization['delete']);
	}//end testACaseTypesHandlingTeamsReachTheLiveSchema()

	/**
	 * The live block is replaced, not added to, so a removed team goes.
	 *
	 * The schema starts with a stale rule for a team no case type names any
	 * more. Building on the live block would keep it.
	 *
	 * @return void
	 */
	public function testAStaleTeamRuleOnTheLiveSchemaIsRemoved(): void {
		$stale = ['group' => 'oud-team', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];
		$this->schema->authorization = ['read' => [$stale]];

		$this->projector(rows: [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]])->reapply();

		$this->assertNotContains($stale, $this->schema->authorization['read']);
	}//end testAStaleTeamRuleOnTheLiveSchemaIsRemoved()

	/**
	 * Nothing changed, nothing written.
	 *
	 * Every case type save calls the projector, so it must not rewrite the
	 * schema when the block is the same.
	 *
	 * @return void
	 */
	public function testAnUnchangedBlockIsNotWrittenAgain(): void {
		$rows = [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]];
		$projector = $this->projector(rows: $rows);

		$this->assertTrue($projector->reapply());
		$this->assertFalse($projector->reapply());
		$this->assertSame(1, $this->mapper->writes);
	}//end testAnUnchangedBlockIsNotWrittenAgain()

	/**
	 * Without OpenRegister nothing is written and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsWritten(): void {
		$this->assertFalse($this->projector(rows: [], hasMapper: false)->reapply());
		$this->assertSame(0, $this->mapper->writes);
	}//end testWithoutOpenRegisterNothingIsWritten()
}//end class
