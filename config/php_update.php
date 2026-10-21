<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PHP updates
    |--------------------------------------------------------------------------
    |
    | php:check-update compares the PHP this container runs with the latest
    | release of the same branch (8.5, …) on php.net, and whether the image
    | the Dockerfile builds from is already out with it. {version} is
    | replaced by the PHP version, e.g. 1-php8.5.12-trixie; keep it in step
    | with the Dockerfile's FROM line.
    |
    */

    'releases_url' => 'https://www.php.net/releases/index.php?json&version={branch}',

    'image_repository' => 'dunglas/frankenphp',

    'image_tag' => '1-php{version}-trixie',

    'image_tag_url' => 'https://hub.docker.com/v2/repositories/{repository}/tags/{tag}',

];
