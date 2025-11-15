<?php

declare(strict_types=1);

error_reporting(E_ERROR | E_PARSE);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$drupalRoot = dirname(__DIR__, 4);

define('DRUPAL_ROOT', $drupalRoot);

$autoloader = require $drupalRoot . '/autoload.php';
require_once $drupalRoot . '/core/includes/bootstrap.inc';

$currentDir = getcwd();
chdir($drupalRoot);

$request = Request::createFromGlobals();
if (!$request->server->get('HTTP_HOST')) {
  $request->server->set('HTTP_HOST', 'localhost');
}
if (!$request->server->get('REMOTE_ADDR')) {
  $request->server->set('REMOTE_ADDR', '127.0.0.1');
}

$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();

$container = $kernel->getContainer();
$manager = $container->get('makerspace_dashboard.chart_builder_manager');
$builder = $manager->getBuilder('education', 'skill_levels');

$filters = [
  'ranges' => [
    'skill_levels' => $argv[1] ?? '1y',
  ],
];

$definition = $builder ? $builder->build($filters) : NULL;
if (!$definition) {
  echo "No definition\n";
  exit(0);
}

echo json_encode($definition->toMetadata(), JSON_PRETTY_PRINT) . PHP_EOL;

chdir($currentDir);
