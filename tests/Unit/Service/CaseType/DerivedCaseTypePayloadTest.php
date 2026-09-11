<?php

/**
 * What separates a duplicate from a version.
 *
 * These two gestures share every mechanic and differ only in a list of fields,
 * which is exactly why the list lives in its own class and gets its own tests.
 * The difference is not cosmetic: a duplicate is a second case type and a
 * version is the same case type later on, and the field that decides which one
 * a reader is looking at is the identifier. Get that wrong in either direction
 * and two unrelated types read as versions of one, or two versions of one type
 * read as unrelated.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DerivedCaseTypePayload.
 *
 * @covers \OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload
 */
class DerivedCaseTypePayloadTest extends TestCase {

	/**
	 * The payload builder under test.
	 *
	 * @var DerivedCaseTypePayload
	 */
	private DerivedCaseTypePayload $payloads;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->payloads = new DerivedCaseTypePayload();
	}//end setUp()

	/**
	 * A published case type with everything filled in.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The source.
	 */
	private function source(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ct-1',
				'@self' => ['id' => 'ct-1'],
				'title' => 'Omgevingsvergunning',
				'identifier' => 'CT-1000',
				'isDraft' => false,
				'version' => 2,
				'publicationRequired' => true,
				'publicationText' => 'Gepubliceerd op het portaal',
				'workflowDefinition' => 'wf-3',
				'relatedCaseTypes' => ['ct-9'],
				'subCaseTypes' => ['ct-8'],
				'processingDeadline' => 'P30D',
			],
			$overrides
		);
	}//end source()

	/**
	 * A duplicate is a second case type, with its own identity.
	 *
	 * @return void
	 */
	public function testADuplicateGetsItsOwnIdentity(): void {
		$payload = $this->payloads->duplicate(source: $this->source());

		self::assertSame('Copy of Omgevingsvergunning', $payload['title']);
		self::assertNotSame('CT-1000', $payload['identifier']);
		self::assertStringStartsWith('CT-1000-copy-', $payload['identifier']);
		self::assertTrue($payload['isDraft']);
	}//end testADuplicateGetsItsOwnIdentity()

	/**
	 * A duplicate starts its own version chain.
	 *
	 * Carrying the source's version number would make two unrelated case types
	 * read as versions of one.
	 *
	 * @return void
	 */
	public function testADuplicateStartsItsOwnChain(): void {
		$payload = $this->payloads->duplicate(source: $this->source());

		self::assertSame(1, $payload['version']);
		self::assertNull($payload['previousVersion']);
		self::assertNull($payload['supersededBy']);
	}//end testADuplicateStartsItsOwnChain()

	/**
	 * A duplicate is not a sibling of the source's related types.
	 *
	 * @return void
	 */
	public function testADuplicateDropsTheSourcesSiblings(): void {
		$payload = $this->payloads->duplicate(source: $this->source());

		self::assertSame([], $payload['relatedCaseTypes']);
		self::assertSame([], $payload['subCaseTypes']);
		self::assertNull($payload['workflowDefinition']);
		self::assertFalse($payload['publicationRequired']);
		self::assertSame('', $payload['publicationText']);
	}//end testADuplicateDropsTheSourcesSiblings()

	/**
	 * A source with no identifier still gets a usable one.
	 *
	 * @return void
	 */
	public function testADuplicateOfAnUnidentifiedTypeStillGetsAnIdentifier(): void {
		$payload = $this->payloads->duplicate(source: $this->source(['identifier' => '']));

		self::assertStringStartsWith('CT-', $payload['identifier']);
		self::assertNotSame('CT-', $payload['identifier']);
	}//end testADuplicateOfAnUnidentifiedTypeStillGetsAnIdentifier()

	/**
	 * 🔴 A version keeps the identity that makes it the same case type.
	 *
	 * ZGW's `identificatie` is what makes two rows versions of one zaaktype.
	 * Regenerating it, which is right for a duplicate, would break the chain
	 * silently: two versions with different identifiers are two case types.
	 *
	 * @return void
	 */
	public function testAVersionKeepsTheTitleAndTheIdentifier(): void {
		$payload = $this->payloads->nextVersion(source: $this->source(), sourceId: 'ct-1');

		self::assertSame('Omgevingsvergunning', $payload['title']);
		self::assertSame('CT-1000', $payload['identifier']);
	}//end testAVersionKeepsTheTitleAndTheIdentifier()

	/**
	 * A version is one number on, links back, and supersedes nothing yet.
	 *
	 * @return void
	 */
	public function testAVersionChainsBackAndSupersedesNothingYet(): void {
		$payload = $this->payloads->nextVersion(source: $this->source(), sourceId: 'ct-1');

		self::assertSame(3, $payload['version']);
		self::assertSame('ct-1', $payload['previousVersion']);
		self::assertNull($payload['supersededBy']);
		self::assertTrue($payload['isDraft']);
	}//end testAVersionChainsBackAndSupersedesNothingYet()

	/**
	 * A version keeps the links a duplicate drops.
	 *
	 * It is the same case type, so its relations to sibling and sub case types
	 * are still its relations.
	 *
	 * @return void
	 */
	public function testAVersionKeepsTheSourcesSiblings(): void {
		$payload = $this->payloads->nextVersion(source: $this->source(), sourceId: 'ct-1');

		self::assertSame(['ct-9'], $payload['relatedCaseTypes']);
		self::assertSame(['ct-8'], $payload['subCaseTypes']);
	}//end testAVersionKeepsTheSourcesSiblings()

	/**
	 * A version drops the workflow pin, for the reason a duplicate does.
	 *
	 * It names a template belonging to the previous version, and a type
	 * claiming a default route its own Workflow tab cannot show is worse than a
	 * type claiming none.
	 *
	 * @return void
	 */
	public function testAVersionDropsTheWorkflowPin(): void {
		$payload = $this->payloads->nextVersion(source: $this->source(), sourceId: 'ct-1');

		self::assertNull($payload['workflowDefinition']);
	}//end testAVersionDropsTheWorkflowPin()

	/**
	 * Both gestures strip identity, so the save creates rather than updates.
	 *
	 * @return void
	 */
	public function testBothGesturesStripIdentity(): void {
		foreach (
			[
				$this->payloads->duplicate(source: $this->source()),
				$this->payloads->nextVersion(source: $this->source(), sourceId: 'ct-1'),
			] as $payload
		) {
			self::assertArrayNotHasKey('id', $payload);
			self::assertArrayNotHasKey('@self', $payload);
		}
	}//end testBothGesturesStripIdentity()

	/**
	 * A case type saved before the version property existed is version one.
	 *
	 * Answering zero would make the NEXT version one as well, which is two rows
	 * both claiming to be the first.
	 *
	 * @return void
	 */
	public function testAnUnversionedCaseTypeIsVersionOne(): void {
		self::assertSame(1, $this->payloads->versionOf(caseType: []));
		self::assertSame(1, $this->payloads->versionOf(caseType: ['version' => 0]));
		self::assertSame(1, $this->payloads->versionOf(caseType: ['version' => -3]));
		self::assertSame(4, $this->payloads->versionOf(caseType: ['version' => 4]));
	}//end testAnUnversionedCaseTypeIsVersionOne()

	/**
	 * The next version of an unversioned case type is two, not one.
	 *
	 * @return void
	 */
	public function testTheNextVersionOfAnUnversionedTypeIsTwo(): void {
		$source = $this->source();
		unset($source['version']);

		$payload = $this->payloads->nextVersion(source: $source, sourceId: 'ct-1');

		self::assertSame(2, $payload['version']);
	}//end testTheNextVersionOfAnUnversionedTypeIsTwo()

	/**
	 * Everything not named by either gesture is carried over unchanged.
	 *
	 * The blueprint is the point: a version that quietly lost the deadline it
	 * governs cases by would be a different case type wearing the same name.
	 *
	 * @return void
	 */
	public function testUnnamedFieldsAreCarriedOver(): void {
		self::assertSame(
			'P30D',
			$this->payloads->nextVersion(source: $this->source(), sourceId: 'ct-1')['processingDeadline']
		);
		self::assertSame(
			'P30D',
			$this->payloads->duplicate(source: $this->source())['processingDeadline']
		);
	}//end testUnnamedFieldsAreCarriedOver()
}//end class
