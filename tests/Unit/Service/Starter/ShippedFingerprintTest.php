<?php

/**
 * Shipped Fingerprint and Shipped Sets Test
 *
 * Pins how a shipped row is fingerprinted (bookkeeping keys ignored, key
 * order irrelevant, list order relevant), when a row counts as untouched by
 * the instance (same fingerprint, same shipped projection, or not saved since
 * it was stamped), and which version a shipped set has.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
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

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShippedFingerprint and ShippedSets.
 */
#[CoversClass(ShippedFingerprint::class)]
#[CoversClass(ShippedSets::class)]
final class ShippedFingerprintTest extends TestCase {
	/**
	 * Subject under test.
	 *
	 * @var ShippedFingerprint
	 */
	private ShippedFingerprint $fingerprint;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fingerprint = new ShippedFingerprint();
	}//end setUp()

	/**
	 * The hash is the sha256 of the canonical JSON: sorted keys, no bookkeeping keys, unescaped.
	 *
	 * @return void
	 */
	public function testHashIsOfTheCanonicalJson(): void {
		$object = ['title' => 'Vergunning/aanvraag é', 'id' => 5, 'a' => ['z' => 1, 'uuid' => 'x', 'b' => 2]];

		self::assertSame(
			expected: hash('sha256', '{"a":{"b":2,"z":1},"title":"Vergunning/aanvraag é"}'),
			actual: $this->fingerprint->hashOf(object: $object)
		);
	}//end testHashIsOfTheCanonicalJson()

	/**
	 * Bookkeeping keys at any depth and key order do not change the hash; list order and values do.
	 *
	 * @return void
	 */
	public function testWhatChangesTheHash(): void {
		$base = $this->fingerprint->hashOf(object: ['name' => 'Rol', 'steps' => ['a', 'b'], 'meta' => ['x' => 1, 'y' => 2]]);

		$bookkeeping = [
			'@self'       => ['owner' => 'admin'],
			'id'          => 1,
			'uuid'        => 'u',
			'version'     => '2',
			'created'     => 'c',
			'updated'     => 'u',
			'published'   => 'p',
			'depublished' => 'd',
		];
		$reordered = $this->fingerprint->hashOf(object: ['meta' => ['y' => 2, 'x' => 1, 'id' => 9]] + $bookkeeping + ['steps' => ['a', 'b'], 'name' => 'Rol']);

		self::assertSame(expected: $base, actual: $reordered);
		self::assertNotSame(expected: $base, actual: $this->fingerprint->hashOf(object: ['name' => 'Rol', 'steps' => ['b', 'a'], 'meta' => ['x' => 1, 'y' => 2]]));
		self::assertNotSame(expected: $base, actual: $this->fingerprint->hashOf(object: ['name' => 'Rol!', 'steps' => ['a', 'b'], 'meta' => ['x' => 1, 'y' => 2]]));
	}//end testWhatChangesTheHash()

	/**
	 * A row matches its own fingerprint, not another's, and never an empty one.
	 *
	 * @return void
	 */
	public function testMatches(): void {
		$object = ['name' => 'Rol'];
		$hash   = $this->fingerprint->hashOf(object: $object);

		self::assertTrue(condition: $this->fingerprint->matches(object: $object, fingerprint: $hash));
		self::assertFalse(condition: $this->fingerprint->matches(object: ['name' => 'Ander'], fingerprint: $hash));
		self::assertFalse(condition: $this->fingerprint->matches(object: $object, fingerprint: ''));
	}//end testMatches()

	/**
	 * The shipped projection keeps only the shipped keys that carry a value.
	 *
	 * @return void
	 */
	public function testMatchesShippedProjectsOnFilledShippedKeys(): void {
		$shipped = $this->fingerprint->hashOf(object: ['name' => 'Rol', 'code' => 'R1']);
		$stored  = ['name' => 'Rol', 'code' => 'R1', 'description' => null, 'tags' => [], 'note' => '', 'addedHere' => 'x'];

		self::assertTrue(condition: $this->fingerprint->matchesShipped(object: $stored, fingerprint: $shipped, shippedKeys: ['name', 'code', 'description', 'tags', 'note', 'missing']));
		self::assertFalse(condition: $this->fingerprint->matchesShipped(object: ['name' => 'Rol', 'code' => 'R2'], fingerprint: $shipped, shippedKeys: ['name', 'code']));
	}//end testMatchesShippedProjectsOnFilledShippedKeys()

	/**
	 * Stored and stamp moments, and whether the row was not saved since the stamp.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: bool}>
	 */
	public static function savedMoments(): array {
		return [
			'saved before the stamp' => [['updated' => '2026-01-01T10:00:00+00:00'], '2026-01-02T10:00:00+00:00', true],
			'saved at the stamp'     => [['updated' => '2026-01-02T10:00:00+00:00'], '2026-01-02T10:00:00+00:00', true],
			'saved after the stamp'  => [['updated' => '2026-01-03T10:00:00+00:00'], '2026-01-02T10:00:00+00:00', false],
			'no saved moment'        => [[], '2026-01-02T10:00:00+00:00', false],
			'no stamp'               => [['updated' => '2026-01-01T10:00:00+00:00'], 'not a date', false],
			'@self not an array'     => ['broken', '2026-01-02T10:00:00+00:00', false],
		];
	}//end savedMoments()

	/**
	 * A row is "not saved since" only when both moments read and the save is not after the stamp.
	 *
	 * @param mixed  $self      The row's @self.
	 * @param string $stampedAt The stamp moment.
	 * @param bool   $expected  Whether it was not saved since.
	 *
	 * @return void
	 */
	#[DataProvider('savedMoments')]
	public function testNotSavedSince(mixed $self, string $stampedAt, bool $expected): void {
		self::assertSame(expected: $expected, actual: $this->fingerprint->notSavedSince(object: ['@self' => $self], stampedAt: $stampedAt));
	}//end testNotSavedSince()

	/**
	 * A row is untouched when its fingerprint matches, when its shipped projection matches,
	 * or when it was fingerprinted and not saved since the stamp.
	 *
	 * @return void
	 */
	public function testUntouched(): void {
		$object  = ['name' => 'Rol', 'extra' => 'local', '@self' => ['updated' => '2026-01-01T00:00:00+00:00']];
		$own     = $this->fingerprint->hashOf(object: $object);
		$shipped = $this->fingerprint->hashOf(object: ['name' => 'Rol']);
		$other   = $this->fingerprint->hashOf(object: ['name' => 'Ander']);

		self::assertTrue(condition: $this->fingerprint->untouched(object: $object, fingerprint: $own, shippedFingerprint: $other));
		self::assertTrue(condition: $this->fingerprint->untouched(object: $object, fingerprint: $other, shippedFingerprint: $shipped, shippedKeys: ['name']));
		self::assertFalse(condition: $this->fingerprint->untouched(object: $object, fingerprint: $other, shippedFingerprint: $shipped));
		self::assertTrue(condition: $this->fingerprint->untouched(object: $object, fingerprint: $other, shippedFingerprint: $other, stampedAt: '2026-02-01T00:00:00+00:00'));
		self::assertFalse(condition: $this->fingerprint->untouched(object: $object, fingerprint: '', shippedFingerprint: $other, stampedAt: '2026-02-01T00:00:00+00:00'));
		self::assertFalse(condition: $this->fingerprint->untouched(object: $object, fingerprint: $other, shippedFingerprint: $other, stampedAt: '2025-12-01T00:00:00+00:00'));
	}//end testUntouched()

	/**
	 * The built-in sets answer their own version without reading a package.
	 *
	 * @return void
	 */
	public function testBuiltInSetsHaveTheirOwnVersion(): void {
		$packages = $this->createMock(PackageDirectory::class);
		$packages->expects(self::never())->method('read');
		$sets = new ShippedSets(packages: $packages);

		self::assertSame(expected: '1.0.0', actual: $sets->versionOf(set: ShippedSets::VTH));
		self::assertSame(expected: '1.0.0', actual: $sets->versionOf(set: ShippedSets::MUNICIPAL_ROLES));
	}//end testBuiltInSetsHaveTheirOwnVersion()

	/**
	 * Package manifests, and the version they give the set.
	 *
	 * @return array<string, array{0: array<string,mixed>|null, 1: string}>
	 */
	public static function packages(): array {
		return [
			'semantic version'  => [['files' => [PackageDirectory::MANIFEST => '{"version":"2.3.10"}']], '2.3.10'],
			'not semantic'      => [['files' => [PackageDirectory::MANIFEST => '{"version":"2.3"}']], ''],
			'prerelease'        => [['files' => [PackageDirectory::MANIFEST => '{"version":"2.3.1-beta"}']], ''],
			'no version'        => [['files' => [PackageDirectory::MANIFEST => '{}']], ''],
			'not json'          => [['files' => [PackageDirectory::MANIFEST => 'nope']], ''],
			'no manifest'       => [['files' => []], ''],
			'no package'        => [null, ''],
		];
	}//end packages()

	/**
	 * Another set takes the semantic version of its package manifest, else none.
	 *
	 * @param array<string,mixed>|null $package  What the package directory reads.
	 * @param string                   $expected The version.
	 *
	 * @return void
	 */
	#[DataProvider('packages')]
	public function testPackageSetTakesItsManifestVersion(?array $package, string $expected): void {
		$packages = $this->createMock(PackageDirectory::class);
		$packages->expects(self::once())->method('read')->with('omgevingswet')->willReturn($package);

		self::assertSame(expected: $expected, actual: (new ShippedSets(packages: $packages))->versionOf(set: 'omgevingswet'));
	}//end testPackageSetTakesItsManifestVersion()

	/**
	 * Without a package directory another set has no version.
	 *
	 * @return void
	 */
	public function testNoPackageDirectoryMeansNoVersion(): void {
		self::assertSame(expected: '', actual: (new ShippedSets())->versionOf(set: 'omgevingswet'));
	}//end testNoPackageDirectoryMeansNoVersion()
}//end class
