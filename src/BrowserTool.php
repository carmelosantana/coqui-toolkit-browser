<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Browser;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Browser\Runtime\PlaywrightRunner;

/**
 * Tool for browser navigation and page interaction via playwright-cli.
 *
 * Provides commands to open pages, click elements, fill forms, type text,
 * press keys, scroll, hover, select options, evaluate JavaScript, and more.
 * Element references (e.g. e5, e12) come from the snapshot command in
 * BrowserCaptureTool — take a snapshot first to discover available refs.
 */
final class BrowserTool implements ToolInterface
{
    public function __construct(
        private readonly PlaywrightRunner $runner,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function description(): string
    {
        return <<<'DESC'
            Navigate and interact with web pages in a headless browser.

            This tool controls a real browser via playwright-cli. Use it to open URLs,
            click elements, fill forms, type text, press keys, and perform other page
            interactions.

            **Workflow:**
            1. Use `open` to navigate to a URL (this also launches the browser if needed)
            2. Use `browser_capture` action `snapshot` to see the page structure and element refs
            3. Use refs (e.g. e5, e12) from the snapshot to interact with elements
            4. Take another snapshot after interactions to verify results

            Element refs are identifiers like `e5`, `e12` from the snapshot output.
            They map to specific interactive elements on the page.

            Available actions:
            - open: Navigate to a URL (launches browser if not running)
            - click: Click an element by ref or selector
            - dblclick: Double-click an element
            - fill: Clear a field and type new text
            - type: Type text (appends to existing content)
            - press: Press a keyboard key (Enter, Tab, ArrowDown, etc.)
            - keydown: Hold a key down
            - keyup: Release a key
            - hover: Hover over an element
            - select: Select a dropdown option
            - check: Check a checkbox
            - uncheck: Uncheck a checkbox
            - scroll: Scroll the page (up/down/left/right)
            - drag: Drag from one element to another
            - upload: Upload a file to a file input
            - back: Navigate back in history
            - forward: Navigate forward in history
            - reload: Reload the current page
            - eval: Execute JavaScript on the page
            - resize: Resize the browser viewport
            - wait: Wait for an element, text, or time delay
            - close: Close the browser
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The browser action to perform',
                values: [
                    'open', 'click', 'dblclick', 'fill', 'type', 'press',
                    'keydown', 'keyup', 'hover', 'select', 'check', 'uncheck',
                    'scroll', 'drag', 'upload', 'back', 'forward', 'reload',
                    'eval', 'resize', 'wait', 'close',
                ],
                required: true,
            ),
            new StringParameter(
                name: 'url',
                description: 'URL to navigate to. Required for open action.',
                required: false,
            ),
            new StringParameter(
                name: 'ref',
                description: 'Element ref from snapshot (e.g. "e5", "e12"). Used for click, fill, type, hover, select, check, uncheck, dblclick, drag.',
                required: false,
            ),
            new StringParameter(
                name: 'value',
                description: 'Text value for fill/type/select/eval/wait. For fill and type: the text to enter. For select: the option value. For eval: JavaScript code. For wait: CSS selector or milliseconds.',
                required: false,
            ),
            new StringParameter(
                name: 'key',
                description: 'Key name for press/keydown/keyup (e.g. "Enter", "Tab", "ArrowDown", "Control+a").',
                required: false,
            ),
            new StringParameter(
                name: 'target_ref',
                description: 'Target element ref for drag action (element to drag to).',
                required: false,
            ),
            new StringParameter(
                name: 'file_path',
                description: 'File path for upload action.',
                required: false,
            ),
            new StringParameter(
                name: 'direction',
                description: 'Scroll direction: up, down, left, right. Default: down.',
                required: false,
            ),
            new NumberParameter(
                name: 'scroll_amount',
                description: 'Scroll amount in pixels. Default: 300.',
                required: false,
                integer: true,
            ),
            new NumberParameter(
                name: 'width',
                description: 'Viewport width for resize action.',
                required: false,
                integer: true,
                minimum: 320,
            ),
            new NumberParameter(
                name: 'height',
                description: 'Viewport height for resize action.',
                required: false,
                integer: true,
                minimum: 240,
            ),
            new StringParameter(
                name: 'session',
                description: 'Browser session name. Defaults to workspace-scoped session. Use to manage multiple independent browsers.',
                required: false,
            ),
            new BoolParameter(
                name: 'headed',
                description: 'Show the browser window (visible mode). Default: false (headless).',
                required: false,
            ),
            new StringParameter(
                name: 'browser_engine',
                description: 'Browser engine to use: chrome, firefox, webkit, msedge. Default: chrome. Only applies to open action.',
                required: false,
            ),
            new BoolParameter(
                name: 'persistent',
                description: 'Persist browser profile to disk for state preservation across restarts. Only applies to open action.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $session = $input['session'] ?? '';

        return match ($action) {
            'open' => $this->open($input, $session),
            'click' => $this->simpleRefAction('click', $input, $session),
            'dblclick' => $this->simpleRefAction('dblclick', $input, $session),
            'fill' => $this->fillOrType('fill', $input, $session),
            'type' => $this->fillOrType('type', $input, $session),
            'press' => $this->pressKey('press', $input, $session),
            'keydown' => $this->pressKey('keydown', $input, $session),
            'keyup' => $this->pressKey('keyup', $input, $session),
            'hover' => $this->simpleRefAction('hover', $input, $session),
            'select' => $this->selectAction($input, $session),
            'check' => $this->simpleRefAction('check', $input, $session),
            'uncheck' => $this->simpleRefAction('uncheck', $input, $session),
            'scroll' => $this->scroll($input, $session),
            'drag' => $this->drag($input, $session),
            'upload' => $this->upload($input, $session),
            'back' => $this->noArgAction('go-back', $session),
            'forward' => $this->noArgAction('go-forward', $session),
            'reload' => $this->noArgAction('reload', $session),
            'eval' => $this->evalJs($input, $session),
            'resize' => $this->resize($input, $session),
            'wait' => $this->wait($input, $session),
            'close' => $this->noArgAction('close', $session),
            default => ToolResult::error("Unknown browser action: {$action}"),
        };
    }

    /** @param array<string, mixed> $input */
    private function open(array $input, string $session): ToolResult
    {
        $url = $input['url'] ?? '';
        if ($url === '') {
            return ToolResult::error('URL is required for open action.');
        }

        $args = [];

        // Browser engine
        $engine = $input['browser_engine'] ?? '';
        if ($engine !== '') {
            $args[] = '--browser=' . $engine;
        }

        // Headed mode
        if (!empty($input['headed'])) {
            $args[] = '--headed';
        }

        // Persistent profile
        if (!empty($input['persistent'])) {
            $args[] = '--persistent';
        }

        $args[] = $url;

        $result = $this->runner->run('open', $args, $session, timeout: 60);

        return $this->formatResult($result, "Opened: {$url}");
    }

    /** @param array<string, mixed> $input */
    private function simpleRefAction(string $command, array $input, string $session): ToolResult
    {
        $ref = $input['ref'] ?? '';
        if ($ref === '') {
            return ToolResult::error("Element ref is required for {$command} action. Take a snapshot first to see available refs.");
        }

        $result = $this->runner->run($command, [$ref], $session);

        return $this->formatResult($result, ucfirst($command) . ": {$ref}");
    }

    /** @param array<string, mixed> $input */
    private function fillOrType(string $command, array $input, string $session): ToolResult
    {
        $ref = $input['ref'] ?? '';
        $value = $input['value'] ?? '';

        if ($ref === '') {
            return ToolResult::error("Element ref is required for {$command} action.");
        }
        if ($value === '' && $command === 'fill') {
            // fill with empty string is valid (clears field)
        }

        $args = [$ref, $value];
        $result = $this->runner->run($command, $args, $session);

        $display = strlen($value) > 50 ? substr($value, 0, 47) . '...' : $value;
        return $this->formatResult($result, ucfirst($command) . " {$ref}: \"{$display}\"");
    }

    /** @param array<string, mixed> $input */
    private function pressKey(string $command, array $input, string $session): ToolResult
    {
        $key = $input['key'] ?? '';
        if ($key === '') {
            return ToolResult::error("Key name is required for {$command} action (e.g. Enter, Tab, ArrowDown).");
        }

        $result = $this->runner->run($command, [$key], $session);

        return $this->formatResult($result, ucfirst($command) . ": {$key}");
    }

    /** @param array<string, mixed> $input */
    private function selectAction(array $input, string $session): ToolResult
    {
        $ref = $input['ref'] ?? '';
        $value = $input['value'] ?? '';

        if ($ref === '') {
            return ToolResult::error('Element ref is required for select action.');
        }
        if ($value === '') {
            return ToolResult::error('Value is required for select action.');
        }

        $result = $this->runner->run('select', [$ref, $value], $session);

        return $this->formatResult($result, "Select {$ref}: \"{$value}\"");
    }

    /** @param array<string, mixed> $input */
    private function scroll(array $input, string $session): ToolResult
    {
        $direction = $input['direction'] ?? 'down';
        $amount = (int) ($input['scroll_amount'] ?? 300);

        $args = [$direction];
        if ($amount !== 300) {
            $args[] = (string) $amount;
        }

        $result = $this->runner->run('scroll', $args, $session);

        return $this->formatResult($result, "Scroll {$direction} {$amount}px");
    }

    /** @param array<string, mixed> $input */
    private function drag(array $input, string $session): ToolResult
    {
        $ref = $input['ref'] ?? '';
        $targetRef = $input['target_ref'] ?? '';

        if ($ref === '' || $targetRef === '') {
            return ToolResult::error('Both ref (source) and target_ref (destination) are required for drag action.');
        }

        $result = $this->runner->run('drag', [$ref, $targetRef], $session);

        return $this->formatResult($result, "Drag {$ref} to {$targetRef}");
    }

    /** @param array<string, mixed> $input */
    private function upload(array $input, string $session): ToolResult
    {
        $filePath = $input['file_path'] ?? '';
        if ($filePath === '') {
            return ToolResult::error('file_path is required for upload action.');
        }

        $result = $this->runner->run('upload', [$filePath], $session);

        return $this->formatResult($result, "Upload: {$filePath}");
    }

    private function noArgAction(string $command, string $session): ToolResult
    {
        $result = $this->runner->run($command, [], $session);

        return $this->formatResult($result, ucfirst(str_replace('-', ' ', $command)));
    }

    /** @param array<string, mixed> $input */
    private function evalJs(array $input, string $session): ToolResult
    {
        $code = $input['value'] ?? '';
        if ($code === '') {
            return ToolResult::error('JavaScript code is required for eval action (pass it in the value parameter).');
        }

        // Use eval with the code as argument
        $result = $this->runner->run('eval', [$code], $session);

        return $this->formatResult($result, 'Eval JavaScript');
    }

    /** @param array<string, mixed> $input */
    private function resize(array $input, string $session): ToolResult
    {
        $width = (int) ($input['width'] ?? 0);
        $height = (int) ($input['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return ToolResult::error('Both width and height are required for resize action.');
        }

        $result = $this->runner->run('resize', [(string) $width, (string) $height], $session);

        return $this->formatResult($result, "Resize: {$width}x{$height}");
    }

    /** @param array<string, mixed> $input */
    private function wait(array $input, string $session): ToolResult
    {
        $value = (string) ($input['value'] ?? '');
        if ($value === '') {
            return ToolResult::error('Value is required for wait action. Pass a CSS selector, milliseconds, or text to wait for.');
        }

        // If it looks like a number, treat as milliseconds
        if (ctype_digit($value)) {
            $result = $this->runner->run('wait', [$value], $session, timeout: (int) ceil((int) $value / 1000) + 10);
        } else {
            $result = $this->runner->run('wait', [$value], $session, timeout: 60);
        }

        return $this->formatResult($result, "Wait: {$value}");
    }

    /**
     * Format the runner result into a ToolResult with consistent output.
     *
     * @param array{exit_code: int, stdout: string, stderr: string} $result
     */
    private function formatResult(array $result, string $action): ToolResult
    {
        $output = "## Browser: {$action}\n\n";

        if ($result['exit_code'] === 127) {
            return ToolResult::error(
                $output . "playwright-cli is not installed.\n\n"
                . "Use `browser_session` with action `setup` to install it automatically.\n"
                . "Alternatively, install globally: `npm install -g @playwright/cli`",
            );
        }

        if ($result['exit_code'] === 124) {
            return ToolResult::error($output . "Command timed out.\n\n" . $result['stderr']);
        }

        $stdout = $result['stdout'];
        $stderr = $result['stderr'];

        if ($result['exit_code'] !== 0) {
            $errorMsg = $stderr !== '' ? $stderr : $stdout;
            return ToolResult::error($output . "**Error (exit code {$result['exit_code']}):**\n\n{$errorMsg}");
        }

        if ($stdout !== '') {
            $output .= $stdout;
        }

        if ($stderr !== '' && $stdout === '') {
            $output .= $stderr;
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
                            'description' => 'The browser action to perform',
                            'enum' => [
                                'open', 'click', 'dblclick', 'fill', 'type', 'press',
                                'keydown', 'keyup', 'hover', 'select', 'check', 'uncheck',
                                'scroll', 'drag', 'upload', 'back', 'forward', 'reload',
                                'eval', 'resize', 'wait', 'close',
                            ],
                        ],
                        'url' => [
                            'type' => 'string',
                            'description' => 'URL to navigate to. Required for open.',
                        ],
                        'ref' => [
                            'type' => 'string',
                            'description' => 'Element ref from snapshot (e.g. "e5"). Used for click, fill, type, hover, etc.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Text value for fill/type/select/eval/wait.',
                        ],
                        'key' => [
                            'type' => 'string',
                            'description' => 'Key name for press/keydown/keyup (e.g. "Enter", "Tab").',
                        ],
                        'target_ref' => [
                            'type' => 'string',
                            'description' => 'Target element ref for drag action.',
                        ],
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'File path for upload action.',
                        ],
                        'direction' => [
                            'type' => 'string',
                            'description' => 'Scroll direction: up, down, left, right.',
                        ],
                        'scroll_amount' => [
                            'type' => 'integer',
                            'description' => 'Scroll amount in pixels. Default: 300.',
                        ],
                        'width' => [
                            'type' => 'integer',
                            'description' => 'Viewport width for resize.',
                            'minimum' => 320,
                        ],
                        'height' => [
                            'type' => 'integer',
                            'description' => 'Viewport height for resize.',
                            'minimum' => 240,
                        ],
                        'session' => [
                            'type' => 'string',
                            'description' => 'Browser session name override.',
                        ],
                        'headed' => [
                            'type' => 'boolean',
                            'description' => 'Show browser window. Default: false.',
                        ],
                        'browser_engine' => [
                            'type' => 'string',
                            'description' => 'Browser engine: chrome, firefox, webkit, msedge.',
                        ],
                        'persistent' => [
                            'type' => 'boolean',
                            'description' => 'Persist browser profile to disk.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
