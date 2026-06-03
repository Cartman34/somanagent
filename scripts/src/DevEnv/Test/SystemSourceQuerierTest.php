<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Test;

use Sowapps\SoManAgent\Script\DevEnv\SystemSourceQuerier;
/**
 * Unit tests for SystemSourceQuerier source routing.
 *
 * Verifies that each source type (default, ppa:*, https://) dispatches
 * to the correct backend (CommandRunner vs. HttpFetcher) and that
 * Packages file content is parsed correctly.
 */
final class SystemSourceQuerierTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testDefaultAptCallsCommandRunner();
        $failed += $this->testPpaAptDoesNotCallAptCachePolicy();
        $failed += $this->testPpaAptParsesPackagesFile();
        $failed += $this->testHttpsRepoAptParsesPackagesFile();
        $failed += $this->testNpmCallsCommandRunner();

        return $failed;
    }

    private function testDefaultAptCallsCommandRunner(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput(
            'apt-cache policy',
            "git:\n  Installed: 2.34.1\n  Candidate: 2.39.2\n  Version table:\n     2.39.2 500\n",
        );
        $fetcher = new FakeHttpFetcher();

        $querier = new SystemSourceQuerier($runner, $fetcher);
        $versions = $querier->queryVersions('apt', 'default', 'git');

        if ($runner->getCallCount() === 0) {
            echo "FAIL testDefaultAptCallsCommandRunner: command runner was not called\n";
            return 1;
        }
        if ($fetcher->getCallCount() !== 0) {
            echo "FAIL testDefaultAptCallsCommandRunner: HTTP fetcher should not be called for default source\n";
            return 1;
        }
        if ($versions !== ['2.39.2']) {
            echo sprintf("FAIL testDefaultAptCallsCommandRunner: expected [2.39.2], got [%s]\n", implode(', ', $versions));
            return 1;
        }

        echo "OK testDefaultAptCallsCommandRunner\n";
        return 0;
    }

    private function testPpaAptDoesNotCallAptCachePolicy(): int
    {
        $runner = new FakeCommandRunner();
        $fetcher = new FakeHttpFetcher();
        // No HTTP response registered — fetch returns null, queryVersions returns []

        $querier = new SystemSourceQuerier($runner, $fetcher);
        $querier->queryVersions('apt', 'ppa:ondrej/php', 'php8.4-cli');

        $aptCacheCalls = array_filter(
            $runner->getCalls(),
            static fn(string $cmd): bool => str_contains($cmd, 'apt-cache policy'),
        );

        if ($aptCacheCalls !== []) {
            echo "FAIL testPpaAptDoesNotCallAptCachePolicy: apt-cache policy must not be called for PPA source\n";
            return 1;
        }

        echo "OK testPpaAptDoesNotCallAptCachePolicy\n";
        return 0;
    }

    private function testPpaAptParsesPackagesFile(): int
    {
        $packagesContent = implode("\n", [
            'Package: php8.3-cli',
            'Version: 8.3.12+1',
            'Architecture: amd64',
            '',
            'Package: php8.4-cli',
            'Version: 8.4.3+1',
            'Architecture: amd64',
            '',
        ]);

        $runner = new FakeCommandRunner();
        $fetcher = new FakeHttpFetcher();
        $fetcher->setResponse('https://ppa.launchpadcontent.net/ondrej/php/', $packagesContent);

        $querier = new SystemSourceQuerier($runner, $fetcher);
        $versions = $querier->queryVersions('apt', 'ppa:ondrej/php', 'php8.4-cli');

        if ($fetcher->getCallCount() === 0) {
            echo "FAIL testPpaAptParsesPackagesFile: HTTP fetcher was not called\n";
            return 1;
        }
        if ($versions !== ['8.4.3+1']) {
            echo sprintf("FAIL testPpaAptParsesPackagesFile: expected [8.4.3+1], got [%s]\n", implode(', ', $versions));
            return 1;
        }

        echo "OK testPpaAptParsesPackagesFile\n";
        return 0;
    }

    private function testHttpsRepoAptParsesPackagesFile(): int
    {
        $packagesContent = implode("\n", [
            'Package: docker-ce',
            'Version: 5:24.0.7-1~ubuntu.22.04~jammy',
            'Architecture: amd64',
            '',
            'Package: docker-ce-cli',
            'Version: 5:24.0.7-1~ubuntu.22.04~jammy',
            'Architecture: amd64',
            '',
        ]);

        $runner = new FakeCommandRunner();
        $fetcher = new FakeHttpFetcher();
        $fetcher->setResponse('https://download.docker.com/', $packagesContent);

        $querier = new SystemSourceQuerier($runner, $fetcher);
        $versions = $querier->queryVersions('apt', 'https://download.docker.com/linux/ubuntu', 'docker-ce');

        if ($fetcher->getCallCount() === 0) {
            echo "FAIL testHttpsRepoAptParsesPackagesFile: HTTP fetcher was not called\n";
            return 1;
        }
        if ($versions !== ['5:24.0.7-1~ubuntu.22.04~jammy']) {
            echo sprintf("FAIL testHttpsRepoAptParsesPackagesFile: expected [5:24.0.7-1~ubuntu.22.04~jammy], got [%s]\n", implode(', ', $versions));
            return 1;
        }

        echo "OK testHttpsRepoAptParsesPackagesFile\n";
        return 0;
    }

    private function testNpmCallsCommandRunner(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput('npm view', "1.0.62\n");
        $fetcher = new FakeHttpFetcher();

        $querier = new SystemSourceQuerier($runner, $fetcher);
        $versions = $querier->queryVersions('npm-global', 'npm', '@anthropic-ai/claude-code');

        if ($runner->getCallCount() === 0) {
            echo "FAIL testNpmCallsCommandRunner: command runner was not called\n";
            return 1;
        }
        if ($fetcher->getCallCount() !== 0) {
            echo "FAIL testNpmCallsCommandRunner: HTTP fetcher should not be called for npm source\n";
            return 1;
        }
        if ($versions !== ['1.0.62']) {
            echo sprintf("FAIL testNpmCallsCommandRunner: expected [1.0.62], got [%s]\n", implode(', ', $versions));
            return 1;
        }

        echo "OK testNpmCallsCommandRunner\n";
        return 0;
    }
}
