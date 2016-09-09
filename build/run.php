<?php
require __DIR__.'/vendor/autoload.php';

$files = require __DIR__.'/files.php';

$outputFile = __DIR__.'/compiled.php';
$strictTypes = false;
$stripComments = true;

$preloader = (new ClassPreloader\Factory)->create();
$handle = $preloader->prepareOutput($outputFile, $strictTypes);

foreach ($files as $file) {
    if (preg_match('#compiled\.php$#', $file)) {
        continue;
    }
    try {
        $code = $preloader->getCode($file, ! $stripComments);

        fwrite($handle, $code."\n");
    } catch (VisitorExceptionInterface $e) {
    }
}

fclose($handle);
