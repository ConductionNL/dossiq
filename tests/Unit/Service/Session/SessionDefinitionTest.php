<?php

/**
 * A session entry of a case type is read and validated in one place.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\Session\SessionDefinition;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionDefinition
 * @covers \OCA\Dossiq\Service\Session\SessionRules
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class SessionDefinitionTest extends TestCase {

	/**
	 * An entry with only a key gets the documented defaults.
	 *
	 * @return void
	 */
	public function testAKeyAloneGetsTheDefaults(): void {
		$definition = new SessionDefinition(config: ['key' => 'talk']);

		self::assertSame('talk', $definition->label);
		self::assertSame([], $definition->inviteeRoles);
		self::assertSame(1, $definition->minInvitees);
		self::assertSame(0, $definition->inspectionFloorDays);
		self::assertFalse($definition->waivable);
		self::assertTrue($definition->minutesRequired);
		self::assertTrue($definition->audioNeedsConsent);
		self::assertSame(1, $definition->attendanceGraceHours);
		self::assertSame('planned', $definition->status(step: 'planned'));
		self::assertSame('afgezien', $definition->status(step: 'waived'));
	}//end testAKeyAloneGetsTheDefaults()

	/**
	 * Malformed entries are refused with a reason.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedEntries(): array {
		return [
			'not an object'      => ['hearing'],
			'no key'             => [['label' => 'Hearing']],
			'key with a space'   => [['key' => 'a hearing']],
			'negative floor'     => [['key' => 'h', 'inspectionFloorDays' => -1]],
			'floor as text'      => [['key' => 'h', 'inspectionFloorDays' => '7']],
			'roles not a list'   => [['key' => 'h', 'inviteeRoles' => 'applicant']],
			'empty role'         => [['key' => 'h', 'inviteeRoles' => ['']]],
			'unknown step'       => [['key' => 'h', 'statuses' => ['archived' => 'gearchiveerd']]],
			'empty status'       => [['key' => 'h', 'statuses' => ['planned' => ' ']]],
		];
	}//end malformedEntries()

	/**
	 * A malformed entry is refused.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return void
	 *
	 * @dataProvider malformedEntries
	 */
	public function testAMalformedEntryIsRefused(mixed $entry): void {
		$this->expectException(InvalidArgumentException::class);
		new SessionDefinition(config: $entry);
	}//end testAMalformedEntryIsRefused()

	/**
	 * A session planned inside the inspection floor is refused, on the floor is accepted.
	 *
	 * @return void
	 */
	public function testTheInspectionFloorIsKept(): void {
		$definition = new SessionDefinition(config: ['key' => 'hearing', 'inspectionFloorDays' => 7]);
		$now        = new DateTimeImmutable('2026-10-11T09:00:00+00:00');

		$definition->rules()->assertInspectionFloor(scheduled: new DateTimeImmutable('2026-10-18T09:00:00+00:00'), now: $now);
		self::assertSame('2026-10-11', $definition->rules()->inspectionDeadline(scheduled: new DateTimeImmutable('2026-10-18T09:00:00+00:00'))->format('Y-m-d'));

		$this->expectException(InvalidArgumentException::class);
		$definition->rules()->assertInspectionFloor(scheduled: new DateTimeImmutable('2026-10-18T08:59:00+00:00'), now: $now);
	}//end testTheInspectionFloorIsKept()

	/**
	 * Only declared invitee roles are accepted, and the minimum is kept.
	 *
	 * @return void
	 */
	public function testInviteeRolesAndMinimumAreKept(): void {
		$definition = new SessionDefinition(config: ['key' => 'hearing', 'inviteeRoles' => ['applicant'], 'minInvitees' => 1]);
		$definition->rules()->assertInvitees(invitees: [['role' => 'applicant']]);

		try {
			$definition->rules()->assertInvitees(invitees: []);
			self::fail('An empty invitee list was accepted.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('at least 1', $e->getMessage());
		}

		$this->expectExceptionMessage('invites only these roles: applicant');
		$definition->rules()->assertInvitees(invitees: [['role' => 'neighbour']]);
	}//end testInviteeRolesAndMinimumAreKept()

	/**
	 * The entry the fragment declares accepts every field the class reads.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresEveryFieldTheClassReads(): void {
		$entry = (new SessionDefinition(
			config: [
				'key'                      => 'hearing',
				'inviteeRoles'             => ['applicant'],
				'inspectionFloorDays'      => 7,
				'waivable'                 => true,
				'statuses'                 => ['executed' => 'executed'],
			]
		))->toArray();

		$validator = new RealSchemaValidator();
		self::assertSame([], $validator->errors(slug: 'caseType', payload: ['sessions' => [$entry]], creating: false));

		// The validator does not refuse undeclared keys, so name them.
		$declared = array_keys($validator->schemas['caseType']['properties']['sessions']['items']['properties']);
		self::assertSame([], array_values(array_diff(array_keys($entry), $declared)));

		$steps = array_keys($validator->schemas['caseType']['properties']['sessions']['items']['properties']['statuses']['properties']);
		self::assertSame(array_keys(SessionDefinition::DEFAULT_STATUSES), $steps);
	}//end testTheFragmentDeclaresEveryFieldTheClassReads()
}//end class
