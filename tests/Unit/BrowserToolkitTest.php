<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Browser\BrowserToolkit;

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new BrowserToolkit(workspacePath: sys_get_temp_dir());

    expect($toolkit)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('tools returns all four browser tools', function () {
    $toolkit = new BrowserToolkit(workspacePath: sys_get_temp_dir());
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(4);

    $names = array_map(fn($tool) => $tool->name(), $tools);
    expect($names)->toBe(['browser', 'browser_capture', 'browser_storage', 'browser_session']);
});

test('each tool implements ToolInterface', function () {
    $toolkit = new BrowserToolkit(workspacePath: sys_get_temp_dir());
    $tools = $toolkit->tools();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolInterface::class);
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $toolkit = new BrowserToolkit(workspacePath: sys_get_temp_dir());

    expect($toolkit->guidelines())
        ->toBeString()
        ->not->toBeEmpty()
        ->toContain('BROWSER-TOOLKIT-GUIDELINES');
});

test('fromEnv creates instance', function () {
    $toolkit = BrowserToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(BrowserToolkit::class);
});
