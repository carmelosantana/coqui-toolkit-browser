<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Browser\Runtime;

/**
 * Core process runner for playwright-cli commands.
 *
 * Resolves the playwright-cli binary, builds CLI commands with session scoping,
 * and executes them via proc_open(). All browser tools delegate to this runner.
 */
final class PlaywrightRunner
{
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_OUTPUT_BYTES = 65_536;

    private string $browserDir;
    private string $resolvedBinary = '';

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $defaultSession = '',
    ) {
        $this->browserDir = rtrim($this->workspacePath, '/') . '/browser';
    }

    /**
     * Execute a playwright-cli command and return the result.
     *
     * @param string $command The playwright-cli subcommand (e.g. 'open', 'click', 'snapshot')
     * @param list<string> $args Arguments for the command
     * @param string $session Override session name (empty = use default)
     * @param int $timeout Timeout in seconds (0 = no timeout)
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(
        string $command,
        array $args = [],
        string $session = '',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): array {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return [
                'exit_code' => 127,
                'stdout' => '',
                'stderr' => 'playwright-cli not found. Use browser_session action "setup" to install it.',
            ];
        }

        $sessionName = $this->resolveSession($session);
        $cmd = $this->buildCommand($binary, $command, $args, $sessionName);

        return $this->execute($cmd, $timeout);
    }

    /**
     * Resolve the playwright-cli binary path.
     *
     * Checks (in order):
     * 1. Previously resolved binary (cached)
     * 2. Workspace-local install: .workspace/browser/node_modules/.bin/playwright-cli
     * 3. Global install: `which playwright-cli`
     * 4. npx fallback: `npx playwright-cli`
     */
    public function resolveBinary(): string
    {
        if ($this->resolvedBinary !== '') {
            return $this->resolvedBinary;
        }

        // Check workspace-local install
        $localBin = $this->browserDir . '/node_modules/.bin/playwright-cli';
        if (file_exists($localBin) && is_executable($localBin)) {
            $this->resolvedBinary = $localBin;
            return $this->resolvedBinary;
        }

        // Check global install
        $which = trim((string) shell_exec('which playwright-cli 2>/dev/null'));
        if ($which !== '' && file_exists($which)) {
            $this->resolvedBinary = $which;
            return $this->resolvedBinary;
        }

        // Check if npx is available as last resort
        $npxCheck = trim((string) shell_exec('which npx 2>/dev/null'));
        if ($npxCheck !== '') {
            $this->resolvedBinary = 'npx playwright-cli';
            return $this->resolvedBinary;
        }

        return '';
    }

    /**
     * Check if the playwright-cli binary is available.
     */
    public function isInstalled(): bool
    {
        return $this->resolveBinary() !== '';
    }

    /**
     * Get the workspace browser directory path.
     */
    public function browserDir(): string
    {
        return $this->browserDir;
    }

    /**
     * Get the resolved default session name.
     */
    public function defaultSession(): string
    {
        if ($this->defaultSession !== '') {
            return $this->defaultSession;
        }

        return 'coqui-' . substr(md5($this->workspacePath), 0, 8);
    }

    /**
     * Clear the cached binary path (forces re-resolution on next call).
     */
    public function clearBinaryCache(): void
    {
        $this->resolvedBinary = '';
    }

    private function resolveSession(string $session): string
    {
        if ($session !== '') {
            return $session;
        }

        return $this->defaultSession();
    }

    /**
     * Build the full CLI command string.
     *
     * @param string $binary The playwright-cli binary path
     * @param string $command The subcommand
     * @param list<string> $args Command arguments
     * @param string $session Session name
     */
    private function buildCommand(
        string $binary,
        string $command,
        array $args,
        string $session,
    ): string {
        $parts = [$binary];

        // Session flag
        $parts[] = '-s=' . escapeshellarg($session);

        // Subcommand
        $parts[] = $command;

        // Arguments
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }

    /**
     * Execute a shell command with timeout support.
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function execute(string $command, int $timeout): array
    {
        $envDir = $this->browserDir;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Set PLAYWRIGHT_CLI_HOME equivalent — working directory for artifacts
        $env = null;
        if (is_dir($envDir)) {
            $env = array_merge(getenv(), [
                'HOME' => $envDir,
            ]);
        }

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            is_dir($envDir) ? $envDir : null,
            $env,
        );

        if (!is_resource($process)) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start playwright-cli process.',
            ];
        }

        fclose($pipes[0]);

        // Non-blocking read with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';

            $stdout .= $out;
            $stderr .= $err;

            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process, 15); // SIGTERM
                usleep(100_000);
                proc_terminate($process, 9);  // SIGKILL
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [
                    'exit_code' => 124,
                    'stdout' => $this->truncateOutput($stdout),
                    'stderr' => "Command timed out after {$timeout}s.\n" . $this->truncateOutput($stderr),
                ];
            }

            usleep(10_000);
        }

        // Read any remaining output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $this->truncateOutput(trim($stdout)),
            'stderr' => $this->truncateOutput(trim($stderr)),
        ];
    }

    private function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)
            . "\n\n[Output truncated at " . self::MAX_OUTPUT_BYTES . " bytes]";
    }
}
