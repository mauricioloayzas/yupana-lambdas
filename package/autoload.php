<?php

// TCPDF (para los reportes en PDF) no viene en el layer compartido
// (mauloasan-shared-{stage}-vendor, construido solo desde el vendor propio de
// bob-contruye) — se vendoriza aparte en este proyecto y se empaqueta con la
// Lambda (ver package.patterns en serverless.yml), así que hace falta un
// segundo autoloader además del de /opt. Mismo patrón que caja-registradora.
if (file_exists('/opt/vendor/autoload.php')) {
    $loader = require '/opt/vendor/autoload.php';
    if (file_exists('/var/task/vendor/autoload.php')) {
        require_once '/var/task/vendor/autoload.php';
    }
} else {
    $loader = require __DIR__ . '/../vendor/autoload.php';
}

$loader->addPsr4('App\\Common\\', [__DIR__ . '/Common/src/']);
