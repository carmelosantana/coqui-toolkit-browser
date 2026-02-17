<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Browser;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Browser\Runtime\PlaywrightRunner;

/**
 * Tool for managing browser storage: cookies, localStorage, sessionStorage,
 * and full storage state save/load.
 *
 * Enables the agent to persist login sessions, manage cookies, and maintain
 * state across browser restarts via state-save/state-load.
 */
final class BrowserStorageTool implements ToolInterface
{
    public function __construct(
        private readonly PlaywrightRunner $runner,
        private readonly string $workspacePath,
    ) {}

    public function name(): string
    {
        return 'browser_storage';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage browser storage: cookies, localStorage, sessionStorage, and full state.

            Use this tool to read, write, and clear browser storage. This is essential for:
            - Maintaining login sessions across browser restarts
            - Managing cookies for authenticated workflows
            - Saving and restoring complete browser state (cookies + localStorage)
            - Reading stored data for debugging or verification

            Available actions:
            - cookie_list: List all cookies (optionally filter by domain)
            - cookie_get: Get a specific cookie by name
            - cookie_set: Set a cookie with name, value, and optional flags
            - cookie_delete: Delete a specific cookie by name
            - cookie_clear: Clear all cookies
            - localstorage_list: List all localStorage items
            - localstorage_get: Get a localStorage value by key
            - localstorage_set: Set a localStorage key-value pair
            - localstorage_delete: Delete a localStorage item
            - localstorage_clear: Clear all localStorage
            - sessionstorage_list: List all sessionStorage items
            - sessionstorage_get: Get a sessionStorage value by key
            - sessionstorage_set: Set a sessionStorage key-value pair
            - sessionstorage_delete: Delete a sessionStorage item
            - sessionstorage_clear: Clear all sessionStorage
            - state_save: Save complete browser state (cookies + localStorage) to a file
            - state_load: Restore browser state from a previously saved file

            **Tip:** Use `state_save` after logging into a website, then `state_load`
            in future sessions to skip the login process.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The storage action to perform',
                values: [
                    'cookie_list', 'cookie_get', 'cookie_set', 'cookie_delete', 'cookie_clear',
                    'localstorage_list', 'localstorage_get', 'localstorage_set', 'localstorage_delete', 'localstorage_clear',
                    'sessionstorage_list', 'sessionstorage_get', 'sessionstorage_set', 'sessionstorage_delete', 'sessionstorage_clear',
                    'state_save', 'state_load',
                ],
                required: true,
            ),
            new StringParameter(
                name: 'name',
                description: 'Cookie or storage key name. Required for get/set/delete operations.',
                required: false,
            ),
            new StringParameter(
                name: 'value',
                description: 'Value to set for cookie_set, localstorage_set, or sessionstorage_set.',
                required: false,
            ),
            new StringParameter(
                name: 'domain',
                description: 'Cookie domain for filtering (cookie_list) or scoping (cookie_set).',
                required: false,
            ),
            new StringParameter(
                name: 'filename',
                description: 'State file name for state_save/state_load. Auto-generated if not provided for save.',
                required: false,
            ),
            new BoolParameter(
                name: 'http_only',
                description: 'Set cookie as HttpOnly. Only for cookie_set.',
                required: false,
            ),
            new BoolParameter(
                name: 'secure',
                description: 'Set cookie as Secure. Only for cookie_set.',
                required: false,
            ),
            new StringParameter(
                name: 'same_site',
                description: 'Cookie SameSite attribute: Strict, Lax, or None. Only for cookie_set.',
                required: false,
            ),
            new StringParameter(
                name: 'session',
                description: 'Browser session name override.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $session = $input['session'] ?? '';

        return match ($action) {
            // Cookies
            'cookie_list' => $this->cookieList($input, $session),
            'cookie_get' => $this->cookieGet($input, $session),
            'cookie_set' => $this->cookieSet($input, $session),
            'cookie_delete' => $this->cookieDelete($input, $session),
            'cookie_clear' => $this->noArgCommand('cookie-clear', 'Cookies Cleared', $session),
            // localStorage
            'localstorage_list' => $this->noArgCommand('localstorage-list', 'LocalStorage', $session),
            'localstorage_get' => $this->storageGet('localstorage-get', $input, $session),
            'localstorage_set' => $this->storageSet('localstorage-set', $input, $session),
            'localstorage_delete' => $this->storageDelete('localstorage-delete', $input, $session),
            'localstorage_clear' => $this->noArgCommand('localstorage-clear', 'LocalStorage Cleared', $session),
            // sessionStorage
            'sessionstorage_list' => $this->noArgCommand('sessionstorage-list', 'SessionStorage', $session),
            'sessionstorage_get' => $this->storageGet('sessionstorage-get', $input, $session),
            'sessionstorage_set' => $this->storageSet('sessionstorage-set', $input, $session),
            'sessionstorage_delete' => $this->storageDelete('sessionstorage-delete', $input, $session),
            'sessionstorage_clear' => $this->noArgCommand('sessionstorage-clear', 'SessionStorage Cleared', $session),
            // State
            'state_save' => $this->stateSave($input, $session),
            'state_load' => $this->stateLoad($input, $session),
            default => ToolResult::error("Unknown storage action: {$action}"),
        };
    }

    /** @param array<string, mixed> $input */
    private function cookieList(array $input, string $session): ToolResult
    {
        $args = [];
        $domain = $input['domain'] ?? '';
        if ($domain !== '') {
            $args[] = '--domain=' . $domain;
        }

        $result = $this->runner->run('cookie-list', $args, $session);

        return $this->formatResult($result, 'Cookies');
    }

    /** @param array<string, mixed> $input */
    private function cookieGet(array $input, string $session): ToolResult
    {
        $name = $input['name'] ?? '';
        if ($name === '') {
            return ToolResult::error('Cookie name is required for cookie_get action.');
        }

        $result = $this->runner->run('cookie-get', [$name], $session);

        return $this->formatResult($result, "Cookie: {$name}");
    }

    /** @param array<string, mixed> $input */
    private function cookieSet(array $input, string $session): ToolResult
    {
        $name = $input['name'] ?? '';
        $value = $input['value'] ?? '';

        if ($name === '') {
            return ToolResult::error('Cookie name is required for cookie_set action.');
        }

        $args = [$name, $value];

        $domain = $input['domain'] ?? '';
        if ($domain !== '') {
            $args[] = '--domain=' . $domain;
        }

        if (!empty($input['http_only'])) {
            $args[] = '--httpOnly';
        }

        if (!empty($input['secure'])) {
            $args[] = '--secure';
        }

        $sameSite = $input['same_site'] ?? '';
        if ($sameSite !== '') {
            $args[] = '--sameSite=' . $sameSite;
        }

        $result = $this->runner->run('cookie-set', $args, $session);

        return $this->formatResult($result, "Set cookie: {$name}");
    }

    /** @param array<string, mixed> $input */
    private function cookieDelete(array $input, string $session): ToolResult
    {
        $name = $input['name'] ?? '';
        if ($name === '') {
            return ToolResult::error('Cookie name is required for cookie_delete action.');
        }

        $result = $this->runner->run('cookie-delete', [$name], $session);

        return $this->formatResult($result, "Delete cookie: {$name}");
    }

    /** @param array<string, mixed> $input */
    private function storageGet(string $command, array $input, string $session): ToolResult
    {
        $name = $input['name'] ?? '';
        if ($name === '') {
            return ToolResult::error('Key name is required for storage get action.');
        }

        $result = $this->runner->run($command, [$name], $session);

        return $this->formatResult($result, "Get: {$name}");
    }

    /** @param array<string, mixed> $input */
    private function storageSet(string $command, array $input, string $session): ToolResult
    {
        $name = $input['name'] ?? '';
        $value = $input['value'] ?? '';

        if ($name === '') {
            return ToolResult::error('Key name is required for storage set action.');
        }

        $result = $this->runner->run($command, [$name, $value], $session);

        return $this->formatResult($result, "Set: {$name}");
    }

    /** @param array<string, mixed> $input */
    private function storageDelete(string $command, array $input, string $session): ToolResult
    {
        $name = $input['name'] ?? '';
        if ($name === '') {
            return ToolResult::error('Key name is required for storage delete action.');
        }

        $result = $this->runner->run($command, [$name], $session);

        return $this->formatResult($result, "Delete: {$name}");
    }

    private function noArgCommand(string $command, string $label, string $session): ToolResult
    {
        $result = $this->runner->run($command, [], $session);

        return $this->formatResult($result, $label);
    }

    /** @param array<string, mixed> $input */
    private function stateSave(array $input, string $session): ToolResult
    {
        $stateDir = rtrim($this->workspacePath, '/') . '/browser/states';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $filename = $input['filename'] ?? '';
        $args = [];

        if ($filename !== '') {
            $path = $stateDir . '/' . $filename;
            $args[] = $path;
        }

        $result = $this->runner->run('state-save', $args, $session);

        return $this->formatResult($result, 'State Saved');
    }

    /** @param array<string, mixed> $input */
    private function stateLoad(array $input, string $session): ToolResult
    {
        $filename = $input['filename'] ?? '';
        if ($filename === '') {
            return ToolResult::error('Filename is required for state_load action.');
        }

        // Check if filename is a path or just a name
        $stateDir = rtrim($this->workspacePath, '/') . '/browser/states';
        $path = str_contains($filename, '/') ? $filename : $stateDir . '/' . $filename;

        if (!file_exists($path)) {
            return ToolResult::error("State file not found: {$path}");
        }

        $result = $this->runner->run('state-load', [$path], $session);

        return $this->formatResult($result, 'State Loaded');
    }

    /** @param array{exit_code: int, stdout: string, stderr: string} $result */
    private function formatResult(array $result, string $label): ToolResult
    {
        $output = "## Storage: {$label}\n\n";

        if ($result['exit_code'] === 127) {
            return ToolResult::error(
                $output . "playwright-cli is not installed.\n\n"
                . "Use `browser_session` with action `setup` to install it automatically.",
            );
        }

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error($output . "**Error:** {$error}");
        }

        $stdout = $result['stdout'];
        if ($stdout !== '') {
            $output .= $stdout;
        } else {
            $output .= 'Done.';
        }

        return ToolResult::success($output);
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The storage action to perform',
                            'enum' => [
                                'cookie_list', 'cookie_get', 'cookie_set', 'cookie_delete', 'cookie_clear',
                                'localstorage_list', 'localstorage_get', 'localstorage_set', 'localstorage_delete', 'localstorage_clear',
                                'sessionstorage_list', 'sessionstorage_get', 'sessionstorage_set', 'sessionstorage_delete', 'sessionstorage_clear',
                                'state_save', 'state_load',
                            ],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Cookie or storage key name.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Value to set.',
                        ],
                        'domain' => [
                            'type' => 'string',
                            'description' => 'Cookie domain filter or scope.',
                        ],
                        'filename' => [
                            'type' => 'string',
                            'description' => 'State file name for save/load.',
                        ],
                        'http_only' => [
                            'type' => 'boolean',
                            'description' => 'HttpOnly cookie flag.',
                        ],
                        'secure' => [
                            'type' => 'boolean',
                            'description' => 'Secure cookie flag.',
                        ],
                        'same_site' => [
                            'type' => 'string',
                            'description' => 'SameSite: Strict, Lax, or None.',
                        ],
                        'session' => [
                            'type' => 'string',
                            'description' => 'Browser session name override.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
