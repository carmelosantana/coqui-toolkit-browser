<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\Browser\BrowserTool;
use CoquiBot\Toolkits\Browser\BrowserCaptureTool;
use CoquiBot\Toolkits\Browser\BrowserStorageTool;
use CoquiBot\Toolkits\Browser\BrowserSessionTool;
use CoquiBot\Toolkits\Browser\Runtime\BrowserInstaller;
use CoquiBot\Toolkits\Browser\Runtime\PlaywrightRunner;

test('BrowserTool has correct name and description', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $tool = new BrowserTool($runner);

    expect($tool->name())->toBe('browser');
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('BrowserCaptureTool has correct name', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $tool = new BrowserCaptureTool($runner, sys_get_temp_dir());

    expect($tool->name())->toBe('browser_capture');
});

test('BrowserStorageTool has correct name', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $tool = new BrowserStorageTool($runner, sys_get_temp_dir());

    expect($tool->name())->toBe('browser_storage');
});

test('BrowserSessionTool has correct name', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $installer = new BrowserInstaller($runner->browserDir());
    $tool = new BrowserSessionTool($runner, $installer);

    expect($tool->name())->toBe('browser_session');
});

test('all tools produce valid function schemas', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $installer = new BrowserInstaller($runner->browserDir());

    $tools = [
        new BrowserTool($runner),
        new BrowserCaptureTool($runner, sys_get_temp_dir()),
        new BrowserStorageTool($runner, sys_get_temp_dir()),
        new BrowserSessionTool($runner, $installer),
    ];

    foreach ($tools as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toHaveKey('type');
        expect($schema['type'])->toBe('function');
        expect($schema)->toHaveKey('function');
        expect($schema['function'])->toHaveKey('name');
        expect($schema['function'])->toHaveKey('description');
        expect($schema['function'])->toHaveKey('parameters');
        expect($schema['function']['parameters'])->toHaveKey('properties');
        expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    }
});

test('BrowserTool returns error for unknown action', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $tool = new BrowserTool($runner);

    $result = $tool->execute(['action' => 'nonexistent_action']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('BrowserCaptureTool returns error for unknown action', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $tool = new BrowserCaptureTool($runner, sys_get_temp_dir());

    $result = $tool->execute(['action' => 'nonexistent_action']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('BrowserStorageTool returns error for unknown action', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $tool = new BrowserStorageTool($runner, sys_get_temp_dir());

    $result = $tool->execute(['action' => 'nonexistent_action']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('BrowserSessionTool returns error for unknown action', function () {
    $runner = new PlaywrightRunner(workspacePath: sys_get_temp_dir());
    $installer = new BrowserInstaller($runner->browserDir());
    $tool = new BrowserSessionTool($runner, $installer);

    $result = $tool->execute(['action' => 'nonexistent_action']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
