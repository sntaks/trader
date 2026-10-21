<?php

use App\Services\DerivApiService;

test('deriv sockets verify peer certificates', function () {
    $options = stream_context_get_options(DerivApiService::streamContext());

    expect($options['ssl'])->toMatchArray(['verify_peer' => true, 'verify_peer_name' => true]);
});

test('no socket client disables peer verification', function () {
    $offenders = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file) => preg_match("/'verify_peer(_name)?'\s*=>\s*false/", file_get_contents($file->getPathname())))
        ->map(fn (SplFileInfo $file) => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
