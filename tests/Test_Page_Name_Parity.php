<?php

/*
 * Every page name this plugin renders resolves to a component in the shared
 * frontend package.
 *
 * Inertia resolving a name to nothing is not an error. The response is a 200,
 * the resolver returns undefined, Vue renders nothing, and the panel comes up
 * blank -- which reads as a permissions problem or an empty dataset. Screens
 * shipped that way across six of the backends in this portfolio before anyone
 * noticed, and the controller tests asserting a 200 said they were fine
 * throughout.
 *
 * Neither repo's tests can see the failure alone: a controller test asserts a
 * status, and the frontend never hears the name. This is the comparison,
 * against the manifest the frontend package publishes and this repo vendors at
 * tests/fixtures/escalated-pages.json.
 *
 * Adding a screen goes: component into the frontend, frontend release, refresh
 * the fixture, then render the name here. In that order, or it ships blank.
 */

use PHPUnit\Framework\TestCase;

class Test_Page_Name_Parity extends TestCase
{
    private const MANIFEST = __DIR__.'/fixtures/escalated-pages.json';

    public function test_renders_only_page_names_the_frontend_ships(): void
    {
        $rendered = $this->rendered_pages();

        $this->assertNotEmpty(
            $rendered,
            'found no page names at all, which means this test is not looking where it should'
        );

        $missing = array_values(array_diff(array_keys($rendered), $this->shipped_pages()));
        sort($missing);

        $this->assertSame([], $missing, $this->explain($missing, $rendered));
    }

    public function test_the_manifest_is_present_and_looks_like_one(): void
    {
        // A fixture gone missing or empty would make the test above pass by
        // comparing against nothing.
        $this->assertFileExists(self::MANIFEST);

        $shipped = $this->shipped_pages();

        $this->assertGreaterThan(50, count($shipped));
        $this->assertSame([], array_values(array_filter(
            $shipped,
            static fn ($page) => ! str_starts_with($page, 'Escalated/')
        )));
    }

    /**
     * @return list<string>
     */
    private function shipped_pages(): array
    {
        $manifest = json_decode((string) file_get_contents(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);

        return $manifest['pages'];
    }

    /**
     * Page names rendered anywhere in includes/, mapped to the files that
     * render them, so a failure can name the file and not only the string.
     *
     * @return array<string, list<string>>
     */
    private function rendered_pages(): array
    {
        $found = [];
        $root = dirname(__DIR__).'/includes';

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match_all("/'(Escalated\/[A-Za-z0-9\/_]+)'/", $source, $matches)) {
                continue;
            }

            foreach ($matches[1] as $page) {
                $found[$page][] = basename($file->getPathname());
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, list<string>>  $rendered
     */
    private function explain(array $missing, array $rendered): string
    {
        if ($missing === []) {
            return '';
        }

        $lines = ['these page names have no component in @escalated-dev/escalated, so they render a blank panel:'];

        foreach ($missing as $page) {
            $lines[] = sprintf('  %s  (%s)', $page, implode(', ', array_unique($rendered[$page])));
        }

        $lines[] = '';
        $lines[] = 'Either the name is wrong, or the component has not been released yet.';
        $lines[] = 'If it has been: refresh tests/fixtures/escalated-pages.json from the package.';

        return implode("\n", $lines);
    }
}
