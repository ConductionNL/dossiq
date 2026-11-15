<?php

/**
 * What the create form is told about duplicates, and what the check is sent.
 *
 * DuplicatePolicyTest drives assertCreatable(). This pins the declaration the
 * create form reads (policy, whether this user may override, the groups that
 * may), how the declared override groups are cleaned, the candidate handed to
 * OpenRegister's duplicate check (no override fields, no empty values), and
 * that a malformed answer from OpenRegister counts as "nothing".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\DuplicatePolicy;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The duplicate declaration and the candidate sent to the check.
 *
 * @covers \OCA\Dossiq\Service\Intake\DuplicatePolicy
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */
class DuplicatePolicyDeclarationTest extends TestCase {

	/**
	 * A detection service answering $annotation and $matches, recording candidates.
	 *
	 * @param mixed $annotation The dedup annotation answer.
	 * @param mixed $matches    The checkCandidate answer.
	 *
	 * @return object The fake.
	 */
	private static function detection(mixed $annotation, mixed $matches = []): object {
		return new class ($annotation, $matches) {
			/** @var array<int, array<string, mixed>> */
			public array $candidates = [];

			/**
			 * @param mixed $annotation The annotation.
			 * @param mixed $matches    The matches.
			 */
			public function __construct(private mixed $annotation, private mixed $matches) {
			}

			/**
			 * @param string               $register  The register.
			 * @param string               $schema    The schema.
			 * @param array<string, mixed> $candidate The candidate.
			 *
			 * @return mixed
			 */
			public function checkCandidate(string $register, string $schema, array $candidate): mixed {
				$this->candidates[] = ['register' => $register, 'schema' => $schema, 'candidate' => $candidate];
				return $this->matches;
			}

			/**
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function dedupAnnotation(string $register, string $schema): mixed {
				return $this->annotation;
			}
		};
	}//end detection()

	/**
	 * A policy over the given detection and group membership.
	 *
	 * @param object             $detection The detection service.
	 * @param array<int, string> $memberOf  The groups `alice` is in.
	 *
	 * @return DuplicatePolicy The policy.
	 */
	private function policy(object $detection, array $memberOf = []): DuplicatePolicy {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($detection);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'alice' && in_array($group, $memberOf, true)
		);

		return new DuplicatePolicy(container: $container, groupManager: $groups, logger: new NullLogger());
	}//end policy()

	/**
	 * The user `alice`.
	 *
	 * @return IUser The user.
	 */
	private function alice(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}//end alice()

	/**
	 * A coordinator of a blocking type is told she may override, and by which groups.
	 *
	 * @return void
	 */
	public function testTheDeclarationTellsACoordinatorSheMayOverride(): void {
		$policy = $this->policy(
			detection: self::detection(annotation: ['overrideGroups' => [' coordinators ', '', 'admins', 7]]),
			memberOf: ['admins']
		);

		$this->assertSame(
			expected: ['policy' => 'block', 'mayOverride' => true, 'overrideGroups' => ['coordinators', 'admins', '7']],
			actual: $policy->declarationFor(caseType: ['duplicatePolicy' => ' block '], user: $this->alice())
		);
	}//end testTheDeclarationTellsACoordinatorSheMayOverride()

	/**
	 * Nobody signed in, or outside every group, may not override.
	 *
	 * @return void
	 */
	public function testAnonymousOrOutsideTheGroupsMayNotOverride(): void {
		$policy = $this->policy(detection: self::detection(annotation: ['overrideGroups' => ['coordinators']]));

		$this->assertFalse(condition: $policy->mayOverride(user: null));
		$this->assertFalse(condition: $policy->mayOverride(user: $this->alice()));
		$this->assertSame(expected: 'warn', actual: $policy->declarationFor(caseType: [], user: null)['policy']);
	}//end testAnonymousOrOutsideTheGroupsMayNotOverride()

	/**
	 * A declared group list that is not a list, or no annotation at all, is no groups.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationMeansNoGroups(): void {
		$this->assertSame(expected: [], actual: $this->policy(detection: self::detection(annotation: ['overrideGroups' => 'coordinators']))->overrideGroups());
		$this->assertSame(expected: [], actual: $this->policy(detection: self::detection(annotation: 'not an array'))->overrideGroups());
	}//end testAMalformedDeclarationMeansNoGroups()

	/**
	 * The candidate sent to OpenRegister carries no override fields and no empty values.
	 *
	 * @return void
	 */
	public function testTheCandidateCarriesOnlyFilledCaseFields(): void {
		$detection = self::detection(annotation: [], matches: [['uuid' => 'case-1']]);

		$matches = $this->policy(detection: $detection)->matchesFor(
			case: [
				'title' => 'Lekkage',
				'description' => '',
				'tags' => [],
				'initiator' => null,
				'zero' => 0,
				DuplicatePolicy::FIELD_REASON => 'Tweede melding',
				DuplicatePolicy::FIELD_OVER => 'case-1',
			]
		);

		$this->assertSame(expected: [['uuid' => 'case-1']], actual: $matches);
		$this->assertSame(
			expected: [['register' => 'dossiq', 'schema' => 'case', 'candidate' => ['title' => 'Lekkage', 'zero' => 0]]],
			actual: $detection->candidates
		);
	}//end testTheCandidateCarriesOnlyFilledCaseFields()

	/**
	 * A non-array answer from the duplicate check is no match.
	 *
	 * @return void
	 */
	public function testAMalformedCheckAnswerIsNoMatch(): void {
		$this->assertSame(
			expected: [],
			actual: $this->policy(detection: self::detection(annotation: [], matches: 'yes'))->matchesFor(case: ['title' => 'x'])
		);
	}//end testAMalformedCheckAnswerIsNoMatch()

	/**
	 * Only array matches with a uuid contribute a uuid.
	 *
	 * @return void
	 */
	public function testUuidsSkipNonArrayAndBlankMatches(): void {
		$policy = $this->policy(detection: self::detection(annotation: []));

		$this->assertSame(
			expected: ['a', 'b'],
			actual: $policy->uuidsOf(matches: [['uuid' => ' a '], 'case-x', ['uuid' => '  '], ['title' => 'no uuid'], ['uuid' => 'b']])
		);
	}//end testUuidsSkipNonArrayAndBlankMatches()
}//end class
