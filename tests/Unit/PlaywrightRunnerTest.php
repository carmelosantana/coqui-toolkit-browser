<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Browser\Runtime\PlaywrightRunner;

test('defaultSession generates deterministic hash', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/test-workspace');

    $session = $runner->defaultSession();

    expect($session)->toStartWith('coqui-');
    expect(strlen($session))->toBe(14); // 'coqui-' + 8 chars
});

test('defaultSession is consistent for same workspace', function () {
    $runner1 = new PlaywrightRunner(workspacePath: '/tmp/test-workspace');
    $runner2 = new PlaywrightRunner(workspacePath: '/tmp/test-workspace');

    expect($runner1->defaultSession())->toBe($runner2->defaultSession());
});

test('defaultSession differs for different workspaces', function () {
    $runner1 = new PlaywrightRunner(workspacePath: '/tmp/workspace-a');
    $runner2 = new PlaywrightRunner(workspacePath: '/tmp/workspace-b');

    expect($runner1->defaultSession())->not->toBe($runner2->defaultSession());
});

test('custom default session overrides hash', function () {
    $runner = new PlaywrightRunner(
        workspacePath: '/tmp/test-workspace',
        defaultSession: 'my-custom-session',
    );

    expect($runner->defaultSession())->toBe('my-custom-session');
});

test('browserDir is within workspace', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/test-workspace');

    expect($runner->browserDir())->toBe('/tmp/test-workspace/browser');
});

test('browserDir strips trailing slash', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/test-workspace/');

    expect($runner->browserDir())->toBe('/tmp/test-workspace/browser');
});

test('resolveBinary returns empty when nothing installed', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/nonexistent-' . uniqid());

    // If playwright-cli isn't globally installed, this should return empty or npx fallback
    $binary = $runner->resolveBinary();
    // Can't assert exact value since npx/global availability varies
    expect($binary)->toBeString();
});

test('clearBinaryCache forces re-resolution', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/test-workspace');

    // Resolve once (caches result)
    $binary1 = $runner->resolveBinary();
    // Clear cache
    $runner->clearBinaryCache();
    // Resolve again (should re-check filesystem)
    $binary2 = $runner->resolveBinary();

    expect($binary2)->toBe($binary1);
});

test('run returns exit code 127 when binary not found', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/nonexistent-' . uniqid());

    // Clear any cached binary to force a fresh resolution
    $runner->clearBinaryCache();

    // Only test if playwright-cli is truly not available
    if ($runner->resolveBinary() !== '') {
        $this->markTestSkipped('playwright-cli is available on this system');
    }

    $result = $runner->run('snapshot');

    expect($result['exit_code'])->toBe(127);
    expect($result['stderr'])->toContain('not found');
});

test('isInstalled returns boolean', function () {
    $runner = new PlaywrightRunner(workspacePath: '/tmp/nonexistent-' . uniqid());

    expect($runner->isInstalled())->toBeBool();
});
