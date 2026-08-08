<?php

/**
 * Tests that the declared Nextcloud floor and the tested matrix agree.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * `appinfo/info.xml` states which Nextcloud versions this app supports.
 * `.github/workflows/code-quality.yml` states which it actually runs against.
 * Nothing has ever held the two together, and they have drifted in both
 * directions within one week:
 *
 *   - #759 raised the floor to 32 while CI still ran stable31.
 *   - #762 restored a 28 floor on the stated ground that "this repo tests
 *     stable31" — by then CI had already been pinned to stable32 only, so the
 *     premise was false when it was written.
 *
 * Two failure modes, and this test catches both:
 *
 *   - A tested leg BELOW the declared floor is a red (or a green) about a
 *     configuration the app does not claim to support.
 *   - A declared floor with NO tested leg at or above it means the supported
 *     range is entirely unexercised.
 *
 * This asserts on each ITEM (every ref in the matrix), not on the container
 * (the matrix merely being non-empty).
 */
class NextcloudFloorMatrixTest extends TestCase
{


    /**
     * Read the declared `<nextcloud min-version>` from appinfo/info.xml.
     *
     * @return int The declared major version.
     */
    private function declaredFloor(): int
    {
        $xml = simplexml_load_file(__DIR__.'/../../../appinfo/info.xml');
        $this->assertNotFalse($xml, 'appinfo/info.xml must parse as XML');

        $nodes = $xml->xpath('//dependencies/nextcloud');
        $this->assertNotEmpty($nodes, 'appinfo/info.xml declares no <nextcloud> dependency');

        $min = (string) $nodes[0]['min-version'];
        $this->assertMatchesRegularExpression(
            '/^\d+$/',
            $min,
            'nextcloud min-version must be a bare major version'
        );

        return (int) $min;
    }//end declaredFloor()


    /**
     * Read the `nextcloud-test-refs` legs from the quality workflow.
     *
     * @return array<int, int> The major version of every tested leg.
     */
    private function testedRefs(): array
    {
        $workflow = file_get_contents(__DIR__.'/../../../.github/workflows/code-quality.yml');
        $this->assertIsString($workflow, '.github/workflows/code-quality.yml must be readable');

        $matched = preg_match(
            "/^\s*nextcloud-test-refs:\s*'(?<json>\[[^\]]*\])'/m",
            $workflow,
            $matches
        );
        $this->assertSame(
            1,
            $matched,
            'Could not find a `nextcloud-test-refs:` line in code-quality.yml. '
            .'If the key was renamed, this test is scanning for something that no '
            .'longer exists and its green would be meaningless.'
        );

        $refs = json_decode($matches['json'], true);
        $this->assertIsArray($refs, 'nextcloud-test-refs must be a JSON array');

        $majors = [];
        foreach ($refs as $ref) {
            $this->assertMatchesRegularExpression(
                '/^stable(\d+)$/',
                (string) $ref,
                sprintf('Unrecognised Nextcloud test ref "%s"', (string) $ref)
            );
            preg_match('/^stable(\d+)$/', (string) $ref, $m);
            $majors[] = (int) $m[1];
        }

        return $majors;
    }//end testedRefs()


    /**
     * The scanners must actually find something before any absence claim
     * derived from them can be believed.
     *
     * @return void
     */
    public function testBothDeclarationsAreActuallyReadable(): void
    {
        $this->assertGreaterThan(0, $this->declaredFloor());
        $this->assertNotEmpty(
            $this->testedRefs(),
            'The tested-ref list parsed as empty. An empty list would make every '
            .'assertion below pass vacuously.'
        );
    }//end testBothDeclarationsAreActuallyReadable()


    /**
     * No CI leg may target a Nextcloud below the declared floor.
     *
     * @return void
     */
    public function testNoTestedLegIsBelowTheDeclaredFloor(): void
    {
        $floor = $this->declaredFloor();
        $below = [];

        foreach ($this->testedRefs() as $major) {
            if ($major < $floor) {
                $below[] = 'stable'.$major;
            }
        }

        $this->assertSame(
            [],
            $below,
            sprintf(
                'appinfo/info.xml declares <nextcloud min-version="%d"/>, but CI still '
                ."runs against %s. Either drop the leg or lower the floor — a leg below "
                .'the floor tests a configuration this app does not support, so neither '
                .'its red nor its green means anything.',
                $floor,
                implode(', ', $below)
            )
        );
    }//end testNoTestedLegIsBelowTheDeclaredFloor()


    /**
     * At least one CI leg must sit at or above the declared floor, so the
     * supported range is exercised rather than merely asserted.
     *
     * @return void
     */
    public function testTheDeclaredFloorIsActuallyExercised(): void
    {
        $floor = $this->declaredFloor();
        $refs  = $this->testedRefs();

        $atOrAbove = array_filter($refs, static fn (int $major): bool => $major >= $floor);

        $this->assertNotEmpty(
            $atOrAbove,
            sprintf(
                'appinfo/info.xml declares a floor of %d but no CI leg runs at or above '
                .'it (legs: %s). The declared support range is entirely untested.',
                $floor,
                implode(', ', array_map(static fn (int $m): string => 'stable'.$m, $refs))
            )
        );
    }//end testTheDeclaredFloorIsActuallyExercised()


}//end class
