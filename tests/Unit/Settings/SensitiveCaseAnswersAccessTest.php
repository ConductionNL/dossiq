<?php

/**
 * A sensitive answer on a case keeps the access it had on its own record.
 *
 * A procedure that held a BSN or an income test on its own record, readable
 * only by dossiq-sensitive, keeps exactly that as a case: `subjectBsn` and
 * `sensitiveProperties` are readable by that group alone. This runs
 * OpenRegister's OWN PropertyRbacHandler, from the checkout beside this app,
 * over the shipped case schema, so the claim is the engine's answer and not a
 * reading of the JSON.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OpenRegister's real classes replace the stubs for the whole process once
 * loaded, so each test runs in a process of its own.
 *
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SensitiveCaseAnswersAccessTest extends TestCase {
	private const OPENREGISTER_LIB = __DIR__ . '/../../../../openregister/lib';

	/**
	 * Put OpenRegister's real source in front of the stubs, for this process only.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$lib = realpath(self::OPENREGISTER_LIB);
		if ($lib === false || is_dir($lib) === false) {
			self::markTestSkipped(message: 'No sibling openregister checkout at ' . self::OPENREGISTER_LIB . '. CI clones it, so this runs there.');
		}

		spl_autoload_register(
			static function (string $class) use ($lib): void {
				$prefix = 'OCA\\OpenRegister\\';
				if (str_starts_with($class, $prefix) === false) {
					return;
				}

				$file = $lib . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
				if (is_file($file) === true) {
					require_once $file;
				}
			},
			true,
			true
		);
	}//end setUp()

	/**
	 * The shipped case schema, fragments merged, as OpenRegister's entity.
	 *
	 * @return object The Schema entity.
	 */
	private function caseSchema(): object {
		$settings = __DIR__ . '/../../../lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
		$class = 'OCA\\OpenRegister\\Db\\Schema';
		$schema = new $class();
		$schema->setProperties($merged['components']['schemas']['case']['properties']);
		return $schema;
	}//end caseSchema()

	/**
	 * A state rule resolver that answers no lifecycle rules, as for a schema without a lifecycle.
	 *
	 * @return object The resolver.
	 */
	private function noStateRules(): object {
		$resolver = $this->createMock(originalClassName: 'OCA\\OpenRegister\\Service\\Lifecycle\\StateFieldRuleResolver');
		$rules = 'OCA\\OpenRegister\\Service\\Lifecycle\\StateFieldRules';
		$resolver->method('resolve')->willReturn(new $rules());
		return $resolver;
	}//end noStateRules()

	/**
	 * What a reader in these groups gets back of one case.
	 *
	 * @param array<int, string> $groups The reader's groups.
	 *
	 * @return array<string, mixed> The readable case.
	 */
	private function readAs(array $groups): array {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('reader');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));

		$handler = 'OCA\\OpenRegister\\Service\\PropertyRbacHandler';
		$rbac = new $handler(
			$session,
			$groupManager,
			$this->createMock(originalClassName: 'OCA\\OpenRegister\\Service\\ConditionMatcher'),
			new NullLogger(),
			$this->noStateRules(),
		);

		return $rbac->filterReadableProperties(
			$this->caseSchema(),
			[
				'id' => 'case-1',
				'title' => 'Bijstandsaanvraag',
				'subjectBsn' => '999990019',
				'properties' => [['propertyDefinition' => 'd-1', 'name' => 'requestKind', 'value' => 'general-social-assistance']],
				'sensitiveProperties' => [['propertyDefinition' => 'd-2', 'name' => 'incomeAssessment', 'value' => '{"income":480}']],
			]
		);
	}//end readAs()

	/**
	 * 🔴 A social-domain handler outside dossiq-sensitive gets neither the BSN nor the sensitive answers.
	 *
	 * @return void
	 */
	public function testASocialDomainReaderOutsideSensitiveGetsNeither(): void {
		$case = $this->readAs(groups: ['dossiq-sociaal-domein', 'dossiq-medewerkers']);

		self::assertArrayNotHasKey(key: 'subjectBsn', array: $case);
		self::assertArrayNotHasKey(key: 'sensitiveProperties', array: $case);
		self::assertSame(expected: 'requestKind', actual: $case['properties'][0]['name'], message: 'the ordinary answers stay readable');
	}//end testASocialDomainReaderOutsideSensitiveGetsNeither()

	/**
	 * A reader in dossiq-sensitive gets both, as on the procedure's own record.
	 *
	 * @return void
	 */
	public function testASensitiveReaderGetsBoth(): void {
		$case = $this->readAs(groups: ['dossiq-sociaal-domein', 'dossiq-sensitive']);

		self::assertSame(expected: '999990019', actual: $case['subjectBsn']);
		self::assertSame(expected: 'incomeAssessment', actual: $case['sensitiveProperties'][0]['name']);
	}//end testASensitiveReaderGetsBoth()
}//end class
