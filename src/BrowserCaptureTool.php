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
 * Tool for capturing page state: snapshots, screenshots, and PDFs.
 *
 * The snapshot action is the primary way the LLM "sees" the page — it returns
 * an accessibility tree with element refs (e5, e12, etc.) that can be used
 * with BrowserTool for interaction.
 */
final class BrowserCaptureTool implements ToolInterface
{
    public function __construct(
        private readonly PlaywrightRunner $runner,
        private readonly string $workspacePath,
    ) {}

    public function name(): string
    {
        return 'browser_capture';
    }

    public function description(): string
    {
        return <<<'DESC'
            Capture the current browser page state for observation and analysis.

            **snapshot** is the most important action — it returns the page's accessibility
            tree with element refs (e.g. e5, e12) that you use for all interactions.
            Always take a snapshot before interacting with a page to discover available elements.

            Available actions:
            - snapshot: Get the page's accessibility tree with interactive element refs.
              This is how you "see" the page. Returns element refs like e5, e12 that map
              to buttons, links, inputs, and other interactive elements.
            - screenshot: Take a PNG screenshot of the page. Useful for visual verification.
              Returns the file path to the saved screenshot.
            - pdf: Save the page as a PDF document.

            **Snapshot tips:**
            - Take a snapshot after every significant interaction to see updated state
            - Element refs change when the page content changes -- always use fresh refs
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The capture action to perform',
                values: ['snapshot', 'screenshot', 'pdf'],
                required: true,
            ),
            new StringParameter(
                name: 'ref',
                description: 'Element ref for scoped screenshot (screenshot of a specific element instead of full page).',
                required: false,
            ),
            new StringParameter(
                name: 'filename',
                description: 'Custom filename for the output. Auto-generated if not provided.',
                required: false,
            ),
            new BoolParameter(
                name: 'full_page',
                description: 'Capture full page screenshot (including content below the fold). Default: false.',
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
            'snapshot' => $this->snapshot($input, $session),
            'screenshot' => $this->screenshot($input, $session),
            'pdf' => $this->pdf($input, $session),
            default => ToolResult::error("Unknown capture action: {$action}"),
        };
    }

    /** @param array<string, mixed> $input */
    private function snapshot(array $input, string $session): ToolResult
    {
        $args = [];

        if (isset($input['filename']) && $input['filename'] !== '') {
            $args[] = '--filename=' . $input['filename'];
        }

        $result = $this->runner->run('snapshot', $args, $session, timeout: 30);

        if ($result['exit_code'] === 127) {
            return ToolResult::error(
                "playwright-cli is not installed.\n\n"
                . "Use `browser_session` with action `setup` to install it automatically.",
            );
        }

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error("## Snapshot Failed\n\n{$error}");
        }

        $output = "## Page Snapshot\n\n";

        // The snapshot output contains the page URL, title, and accessibility tree
        $stdout = $result['stdout'];

        if ($stdout !== '') {
            $output .= $stdout;
        } else {
            $output .= "Snapshot captured (no output returned — check if a page is open).";
        }

        return ToolResult::success($output);
    }

    /** @param array<string, mixed> $input */
    private function screenshot(array $input, string $session): ToolResult
    {
        $args = [];

        // Element-scoped screenshot
        $ref = $input['ref'] ?? '';
        if ($ref !== '') {
            $args[] = $ref;
        }

        // Full page
        if (!empty($input['full_page'])) {
            $args[] = '--full';
        }

        // Custom filename — save to workspace screenshots dir
        $filename = $input['filename'] ?? '';
        if ($filename !== '') {
            $screenshotDir = rtrim($this->workspacePath, '/') . '/browser/screenshots';
            if (!is_dir($screenshotDir)) {
                mkdir($screenshotDir, 0755, true);
            }
            $path = $screenshotDir . '/' . $filename;
            $args[] = '--filename=' . $path;
        }

        $result = $this->runner->run('screenshot', $args, $session, timeout: 30);

        if ($result['exit_code'] === 127) {
            return ToolResult::error(
                "playwright-cli is not installed.\n\n"
                . "Use `browser_session` with action `setup` to install it automatically.",
            );
        }

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error("## Screenshot Failed\n\n{$error}");
        }

        $output = "## Screenshot Captured\n\n";
        $output .= $result['stdout'];

        return ToolResult::success($output);
    }

    /** @param array<string, mixed> $input */
    private function pdf(array $input, string $session): ToolResult
    {
        $filename = $input['filename'] ?? '';

        $args = [];
        if ($filename !== '') {
            $pdfDir = rtrim($this->workspacePath, '/') . '/browser/pdfs';
            if (!is_dir($pdfDir)) {
                mkdir($pdfDir, 0755, true);
            }
            $args[] = '--filename=' . $pdfDir . '/' . $filename;
        }

        $result = $this->runner->run('pdf', $args, $session, timeout: 30);

        if ($result['exit_code'] === 127) {
            return ToolResult::error(
                "playwright-cli is not installed.\n\n"
                . "Use `browser_session` with action `setup` to install it automatically.",
            );
        }

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error("## PDF Export Failed\n\n{$error}");
        }

        $output = "## PDF Saved\n\n";
        $output .= $result['stdout'];

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
                            'description' => 'The capture action to perform',
                            'enum' => ['snapshot', 'screenshot', 'pdf'],
                        ],
                        'ref' => [
                            'type' => 'string',
                            'description' => 'Element ref for scoped screenshot.',
                        ],
                        'filename' => [
                            'type' => 'string',
                            'description' => 'Custom filename for the output.',
                        ],
                        'full_page' => [
                            'type' => 'boolean',
                            'description' => 'Full page screenshot. Default: false.',
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
