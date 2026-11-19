<?php

/*
 * Browser test BT-VEN-002: a money field rendered as `type="number"` without a
 * `step` only accepts whole numbers in the browser, so a CPC bid of 0.75, a
 * room price of 1,299.50 or an ad's sold price of 1,000.5 could not be sent
 * (Seller Center → Advertising, Travel Partner → package editor, Open Souq
 * "mark as sold", broker leads, admin coupon participation).
 */
it('lets every money number field of the panels take decimals', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/panels'))) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (str_contains($line, 'type="number"') && ! str_contains($line, 'step=')
                && preg_match('/name="[^"]*(price|bid|budget|amount|fee|cost|commission|deposit)[^"]*"/i', $line)) {
                $offenders[] = str_replace(resource_path('views/'), '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
