<?php

/**
 * What a case type does about a case that already exists.
 *
 * The cases here are the ones the spec separates, and one it does not say out
 * loud but that decides whether any of it works: a stub standing in for
 * OpenRegister's scorer, so the refusal is driven by the matches rather than by
 * whether the service happened to be installed. A test that let the real
 * container answer would pass on an instance without OpenRegister for the wrong
 * reason, which is the shape of a test that cannot fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\DuplicatePolicy;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the per-case-type duplicate policy.
 *
 * @covers \OCA\Dossiq\Service\Intake\DuplicatePolicy
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class DuplicatePolicyTest extends TestCase {

	/**
	 * The group the case schema names as the one that may file anyway.
	 */
	private const OVERRIDE_GROUP = 'dossiq-coordinators';

	/**
	 * A case that scores against a stored one.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_PAYLOAD = [
		'title' => 'Kapvergunning Eikenlaan',
		'caseType' => 'kapvergunning',
		'requester' => 'jan',
	];

	/**
	 * One match, as OpenRegister scores it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const ONE_MATCH = [
		[
			'uuid' => 'existing-case-uuid',
			'score' => 0.97,
			'matchedOn' => ['requester', 'title'],
			'matchedRules' => [],
		],
	];

	/**
	 * A policy wired over a stubbed scorer.
	 *
	 * @param array<int, array<string, mixed>> $matches What the scorer answers.
	 * @param array<int, string>               $groups  The declared override groups.
	 * @param boolean                          $inGroup Whether the account is in the first of them.
	 * @param boolean                          $installed Whether OpenRegister answers at all.
	 *
	 * @return DuplicatePolicy The policy under test.
	 */
	private function policy(
		array $matches = [],
		array $groups = [self::OVERRIDE_GROUP],
		bool $inGroup = false,
		bool $installed = true,
	): DuplicatePolicy {
		$detection = new class ($matches, $groups) {
			/**
			 * @param array<int, array<string, mixed>> $matches The scored matches.
			 * @param array<int, string>               $groups  The declared override groups.
			 */
			public function __construct(
				private readonly array $matches,
				private readonly array $groups,
			) {
			}

			/**
			 * @param int|string                $register  The register.
			 * @param int|string                $schema    The schema.
			 * @param array<string, mixed>      $candidate The unsaved case.
			 * @param array<int, mixed>|null    $matchRules Unused.
			 * @param float|null                $threshold Unused.
			 *
			 * @return array<int, array<string, mixed>> The matches.
			 */
			public function checkCandidate($register, $schema, array $candidate, ?array $matchRules = null, ?float $threshold = null): array {
				return $this->matches;
			}

			/**
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed> The annotation.
			 */
			public function dedupAnnotation($register, $schema): array {
				return ['overrideGroups' => $this->groups];
			}
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($installed === true) {
			$container->method('get')->willReturn($detection);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn($inGroup);

		return new DuplicatePolicy(
			container: $container,
			groupManager: $groupManager,
			logger: new NullLogger()
		);
	}//end policy()

	/**
	 * An account with a uid.
	 *
	 * @return IUser The account.
	 */
	private function user(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jan');

		return $user;
	}//end user()

	/**
	 * A case type that says nothing warns, which is what every case type does
	 * today.
	 *
	 * @return void
	 */
	public function testACaseTypeThatSaysNothingWarns(): void {
		$this->assertSame(
			DuplicatePolicy::POLICY_WARN,
			$this->policy()->policyFor(caseType: ['title' => 'Melding'])
		);
	}//end testACaseTypeThatSaysNothingWarns()

	/**
	 * An unrecognised policy reads as warn rather than refusing every create.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedPolicyReadsAsWarn(): void {
		$this->assertSame(
			DuplicatePolicy::POLICY_WARN,
			$this->policy()->policyFor(caseType: ['duplicatePolicy' => 'blocking'])
		);
	}//end testAnUnrecognisedPolicyReadsAsWarn()

	/**
	 * Warn never refuses, however strong the match.
	 *
	 * @return void
	 */
	public function testWarnFilesTheCaseAnyway(): void {
		$policy = $this->policy(matches: self::ONE_MATCH);

		$policy->assertCreatable(
			case: self::CASE_PAYLOAD,
			caseType: ['duplicatePolicy' => DuplicatePolicy::POLICY_WARN],
			user: $this->user()
		);

		$this->addToAssertionCount(1);
	}//end testWarnFilesTheCaseAnyway()

	/**
	 * Block with no match is not a refusal: the policy is about duplicates, not
	 * about the case type.
	 *
	 * @return void
	 */
	public function testBlockWithNoMatchFilesTheCase(): void {
		$policy = $this->policy(matches: []);

		$policy->assertCreatable(
			case: self::CASE_PAYLOAD,
			caseType: ['duplicatePolicy' => DuplicatePolicy::POLICY_BLOCK],
			user: $this->user()
		);

		$this->addToAssertionCount(1);
	}//end testBlockWithNoMatchFilesTheCase()

	/**
	 * A handler under block is refused, and the refusal says where to go.
	 *
	 * @return void
	 */
	public function testBlockRefusesAHandler(): void {
		$policy = $this->policy(matches: self::ONE_MATCH, inGroup: false);

		try {
			$policy->assertCreatable(
				case: self::CASE_PAYLOAD,
				caseType: ['duplicatePolicy' => DuplicatePolicy::POLICY_BLOCK],
				user: $this->user()
			);
			$this->fail('A handler filing a duplicate under block should be refused.');
		} catch (RefusedException $e) {
			$this->assertSame(DuplicatePolicy::RULE_BLOCKED, $e->getRule());
			$this->assertSame(RefusedException::STATUS_REFUSED, $e->getStatus());
		}
	}//end testBlockRefusesAHandler()

	/**
	 * A coordinator files the case, with the reason recorded on it.
	 *
	 * @return void
	 */
	public function testBlockLetsACoordinatorFileWithAReason(): void {
		$policy = $this->policy(matches: self::ONE_MATCH, inGroup: true);

		$policy->assertCreatable(
			case: (self::CASE_PAYLOAD + [DuplicatePolicy::FIELD_REASON => 'A second tree, same street']),
			caseType: ['duplicatePolicy' => DuplicatePolicy::POLICY_BLOCK],
			user: $this->user()
		);

		$this->addToAssertionCount(1);
	}//end testBlockLetsACoordinatorFileWithAReason()

	/**
	 * A coordinator without a reason is asked for one, not refused outright.
	 *
	 * @return void
	 */
	public function testACoordinatorWithoutAReasonIsAskedForOne(): void {
		$policy = $this->policy(matches: self::ONE_MATCH, inGroup: true);

		try {
			$policy->assertCreatable(
				case: self::CASE_PAYLOAD,
				caseType: ['duplicatePolicy' => DuplicatePolicy::POLICY_BLOCK],
				user: $this->user()
			);
			$this->fail('A coordinator filing without a reason should be asked for one.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}
	}//end testACoordinatorWithoutAReasonIsAskedForOne()

	/**
	 * A schema naming no override group has said that nobody overrides, so even
	 * a coordinator is refused.
	 *
	 * @return void
	 */
	public function testNoDeclaredGroupMeansNobodyOverrides(): void {
		$policy = $this->policy(matches: self::ONE_MATCH, groups: [], inGroup: true);

		$this->assertFalse($policy->mayOverride(user: $this->user()));
	}//end testNoDeclaredGroupMeansNobodyOverrides()

	/**
	 * Without OpenRegister the case is filed rather than refused, and that is
	 * the documented direction of the failure.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheCaseIsStillFiled(): void {
		$policy = $this->policy(matches: self::ONE_MATCH, installed: false);

		$policy->assertCreatable(
			case: self::CASE_PAYLOAD,
			caseType: ['duplicatePolicy' => DuplicatePolicy::POLICY_BLOCK],
			user: $this->user()
		);

		$this->assertSame([], $policy->matchesFor(case: self::CASE_PAYLOAD));
	}//end testWithoutOpenRegisterTheCaseIsStillFiled()

	/**
	 * The uuids of the matches are what the case records it was filed over.
	 *
	 * @return void
	 */
	public function testTheUuidsOfTheMatchesAreCollected(): void {
		$this->assertSame(
			['existing-case-uuid'],
			$this->policy()->uuidsOf(matches: self::ONE_MATCH)
		);
	}//end testTheUuidsOfTheMatchesAreCollected()
}//end class
