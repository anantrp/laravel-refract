<?php

/*
 * Rule 12: layer borders. Each layer talks to the next only through src/Contracts.
 */

/**
 * The Refract namespaces outside the given ones: each folder of src/ and each class at its root.
 *
 * @param  list<string>  $allowed
 * @return list<string>
 */
function refractNamespacesExcept(array $allowed): array
{
    $namespaces = [];

    foreach (scandir(dirname(__DIR__, 2).'/src') ?: [] as $entry) {
        $name = preg_replace('/\.php$/', '', $entry);

        if (! in_array($entry, ['.', '..'], true) && ! in_array($name, $allowed, true)) {
            $namespaces[] = "Anantrp\\Refract\\{$name}";
        }
    }

    return $namespaces;
}

/**
 * Get every PHP file under the given folders of the package, relative to its root.
 *
 * @param  list<string>  $folders
 * @return list<string>
 */
function packageFiles(array $folders): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach ($folders as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$folder}", FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    sort($files);

    return $files;
}

arch('A1: Capture uses nothing from Transport or Export')
    ->expect('Anantrp\Refract\Capture')
    ->not->toUse(['Anantrp\Refract\Transport', 'Anantrp\Refract\Export']);

arch('A2: Transport uses only Contracts and Support from Refract (the Exporter contract is in Contracts)')
    ->expect('Anantrp\Refract\Transport')
    ->not->toUse(refractNamespacesExcept(['Contracts', 'Support', 'Transport']));

arch('A3: Export uses nothing from Capture or Transport')
    ->expect('Anantrp\Refract\Export')
    ->not->toUse(['Anantrp\Refract\Capture', 'Anantrp\Refract\Transport']);

arch('A4: the platform names langfuse and Langfuse appear only in the Langfuse platform folder and the config', function () {
    $named = array_values(array_filter(
        packageFiles(['src', 'config']),
        fn (string $file) => preg_match('/langfuse/i', (string) file_get_contents(dirname(__DIR__, 2).'/'.$file)) === 1,
    ));

    $outside = array_values(array_filter(
        $named,
        fn (string $file) => ! str_starts_with($file, 'src/Export/Platforms/Langfuse/') && $file !== 'config/refract.php',
    ));

    expect($named)->toContain('config/refract.php')
        ->and($outside)->toBe([]);
});

arch('A5: env() is used only in config/refract.php')
    ->expect('env')
    ->not->toBeUsed();

arch('A5: no package file but config/refract.php calls env()', function () {
    $calling = array_values(array_filter(
        packageFiles(['src', 'config']),
        fn (string $file) => preg_match('/(?<![\w>:$\\\\])env\s*\(/', (string) file_get_contents(dirname(__DIR__, 2).'/'.$file)) === 1,
    ));

    expect($calling)->toBe(['config/refract.php']);
});
