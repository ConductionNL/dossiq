<?php

/**
 * The redaction over a citizen-lookup payload.
 *
 * Two failures are possible here and they look nothing alike. One is a
 * redaction that does not remove, which hands a call handler the transcript of
 * somebody's conversation. The other is a redaction that removes too much,
 * which empties the werkplek for everybody and gets the whole rule taken out
 * again a week later. Both are tested, and the second one is why every
 * assertion below also names a field that must SURVIVE.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CitizenLookupGuard;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * A lookup answers only the fields the caller may read.
 *
 * @covers \OCA\Dossiq\Service\CitizenLookupGuard
 */
class CitizenLookupGuardTest extends TestCase {

	/**
	 * One contact moment carrying all four sensitive fields and three that
	 * are not.
	 *
	 * @var array<string, mixed>
	 */
	private const MOMENT = [
		'notificationChannel' => 'telefoon',
		'direction' => 'inbound',
		'startTime' => '2026-09-18T09:15:00+00:00',
		'callerIdentification' => '+31612345678',
		'geidentificeerdeBurgerId' => '999990032',
		'summary' => 'Belde over de aanvraag en werd boos.',
		'transcript' => 'Goedemorgen, ik bel over...',
	];

	/**
	 * A guard whose group manager answers as given.
	 *
	 * @param bool $sensitive Whether the caller holds `dossiq-sensitive`.
	 *
	 * @return CitizenLookupGuard The guard.
	 */
	private function guard(bool $sensitive): CitizenLookupGuard {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($group === CitizenLookupGuard::SENSITIVE_GROUP
				&& $sensitive === true)
		);

		return new CitizenLookupGuard(groupManager: $groups);
	}

	/**
	 * A caller, with a uid.
	 *
	 * @return IUser The user.
	 */
	private function caller(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sanne');

		return $user;
	}

	/**
	 * The four fields go for a caller outside the group, and nothing else does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function testTheFourFieldsGoForACallerOutsideTheGroup(): void {
		$redacted = $this->guard(sensitive: false)->redactForCaller(
			user: $this->caller(),
			payload: ['contactmomenten' => [self::MOMENT]]
		);

		$row = $redacted['contactmomenten'][0];
		foreach (CitizenLookupGuard::SENSITIVE_CONTACT_FIELDS as $field) {
			$this->assertArrayNotHasKey($field, $row, $field . ' must be withheld');
		}

		// And the lookup is still a lookup. A redaction that empties the row is
		// the failure that gets the whole rule removed a week later.
		$this->assertSame('telefoon', $row['notificationChannel']);
		$this->assertSame('inbound', $row['direction']);
		$this->assertSame('2026-09-18T09:15:00+00:00', $row['startTime']);
	}//end testTheFourFieldsGoForACallerOutsideTheGroup()

	/**
	 * A member receives the row exactly as it was read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function testAMemberReceivesTheRowUntouched(): void {
		$redacted = $this->guard(sensitive: true)->redactForCaller(
			user: $this->caller(),
			payload: ['contactmomenten' => [self::MOMENT]]
		);

		$this->assertSame(self::MOMENT, $redacted['contactmomenten'][0]);
	}//end testAMemberReceivesTheRowUntouched()

	/**
	 * The voorblad's contact moments are redacted too.
	 *
	 * The voorblad is a shape of its own and carries the same rows under a
	 * different key. A redaction that knew only one of the two keys would leave
	 * the werkplek's main screen unprotected while the list behind it looked
	 * correct.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function testTheVoorbladContactMomentsAreRedactedToo(): void {
		$redacted = $this->guard(sensitive: false)->redactForCaller(
			user: $this->caller(),
			payload: [
				'burgerId' => '999990032',
				'openZaken' => [['id' => 'zaak-1', 'title' => 'Vergunning']],
				'recenteContactmomenten' => [self::MOMENT],
				'suggestedTopic' => 'Vergunning',
			]
		);

		$this->assertArrayNotHasKey('summary', $redacted['recenteContactmomenten'][0]);
		$this->assertArrayNotHasKey('transcript', $redacted['recenteContactmomenten'][0]);
		// The rest of the voorblad is what the handler is there for.
		$this->assertSame('999990032', $redacted['burgerId']);
		$this->assertSame('Vergunning', $redacted['openZaken'][0]['title']);
		$this->assertSame('Vergunning', $redacted['suggestedTopic']);
	}//end testTheVoorbladContactMomentsAreRedactedToo()

	/**
	 * A shape this does not recognise is returned untouched.
	 *
	 * Removing keys from a payload nobody planned for is how a redaction
	 * quietly eats a feature that is added later.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function testAnUnknownShapeIsUntouched(): void {
		$payload = ['error' => 'burgerId is required'];

		$this->assertSame(
			$payload,
			$this->guard(sensitive: false)->redactForCaller(user: $this->caller(), payload: $payload)
		);
	}//end testAnUnknownShapeIsUntouched()

	/**
	 * The redaction can never grant a field the caller does not hold.
	 *
	 * The mirror of every other test here: a row that arrived WITHOUT the
	 * sensitive fields must not come back with them, whoever asks. That is the
	 * shape a second evaluator of the declaration would eventually take, and
	 * `array_diff_key` cannot take it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function testTheRedactionNeverGrantsAField(): void {
		$withheld = ['notificationChannel' => 'telefoon'];

		foreach ([true, false] as $sensitive) {
			$out = $this->guard(sensitive: $sensitive)->redactForCaller(
				user: $this->caller(),
				payload: ['contactmomenten' => [$withheld]]
			);
			$this->assertSame($withheld, $out['contactmomenten'][0]);
		}
	}//end testTheRedactionNeverGrantsAField()

	/**
	 * The audit's field list follows the same membership.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function testTheRecordedFieldListFollowsTheMembership(): void {
		$this->assertSame(
			CitizenLookupGuard::SENSITIVE_CONTACT_FIELDS,
			$this->guard(sensitive: true)->revealedFieldsFor(user: $this->caller())
		);
		$this->assertSame([], $this->guard(sensitive: false)->revealedFieldsFor(user: $this->caller()));
	}//end testTheRecordedFieldListFollowsTheMembership()

	/**
	 * A Nextcloud administrator is not waved through the field check.
	 *
	 * The endpoint decision above does wave them through, deliberately. These
	 * four fields are personal data about a citizen, and administering the
	 * instance is not a reason to read the transcript of their call. The double
	 * answers true to `isAdmin` and false to every membership, which is the one
	 * case that tells the two decisions apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function testAnAdministratorIsNotWavedThroughTheFieldCheck(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(false);
		$groups->method('isAdmin')->willReturn(true);
		$guard = new CitizenLookupGuard(groupManager: $groups);

		$this->assertTrue($guard->isCitizenLookupAllowed(user: $this->caller()));
		$this->assertFalse($guard->maySeeSensitiveFields(user: $this->caller()));
	}//end testAnAdministratorIsNotWavedThroughTheFieldCheck()
}//end class
