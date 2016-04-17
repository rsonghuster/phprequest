<?php

require __DIR__.'/vendor/autoload.php';

function get_php_files($path)
{
    $dir = new RecursiveDirectoryIterator($path);
    $iterator = new RecursiveIteratorIterator($dir);
    $regex = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

    $files = array();
    foreach ($regex as $file) {
        $files[] = $file[0];
    }

    return $files;
}

return array_merge(
    get_php_files(__DIR__.'/vendor/psr/http-message/src/'),
    get_php_files(realpath(__DIR__.'/../src/'))
);

