<?php
require 'vendor/autoload.php';

\Slim\Route::setDefaultConditions(array(
    'name' => '[a-z]{3,}'
));
$app = new \Slim\Slim();

/* Helper functions and internal code */

function listGenerators() {
    static $generators;
        if (!isset($generators)) {
            $generators = array();
            $it = new FilesystemIterator(dirname(__FILE__) . "/generators");
            foreach ($it as $dir) {
                if ($it->isDir()) {
                    $name = (string)$it;
                if (file_exists(dirname(__FILE__) . "/generators/" . $name . "/info.json")) {
                    $info = json_decode(getGeneratorInfo($name));
                    if (empty($info->hidden)) {
                        $info->name = $name;
                        $generators[$name] = $info;
                    }
                }
            }
        }
    }
    return $generators;
}

function getGeneratorInfo($name) {
    $filename = dirname(__FILE__) . "/generators/" . $name . "/info.json";
    $json = file_get_contents($filename);
    return $json;
}

function invokeGenerator($name, $rule = '', $params = array()) {
    $dir = dirname(__FILE__) . "/generators/" . $name;
    $command = "rmutt main.rm";
    if (!empty($rule)) {
        $command .= " -e " . $rule;
    }
    foreach ($params as $key => $val) {
        $command .= " -b $key=$val";
    }
    $command = "cd $dir; " . escapeshellcmd($command);
    $output = shell_exec($command);
    return $output;
}

/* Visitor-facing HTML pages */

$app->get('/', 'pageRequest', function() use($app){
    $params = array();
    $params['demo'] = invokeGenerator('rmutt');
    $params['slogan'] = invokeGenerator('rmutt', 'slogan');
    $params['generators'] = listGenerators();

    $app->render("home.html", $params);
});


$app->get('/:name', 'pageRequest', function($name) use($app){
    // Check for a custom template, fall back to the generic one.
    $info = getGeneratorInfo($name);

    $params = json_decode($info, true);
    $params['output'] = invokeGenerator($name);

    $app->render("generator.html", $params);
})->conditions(\Slim\Route::getDefaultConditions());


/* API handlers */

$app->group('/api', function() use ($app) {
  /* Returns a list of available generators. */
  $app->get('/', 'apiRequest', function() use($app) {
    $response = array();
    $generators = listGenerators();
    $app->render(200, array(
      'generators' => $generators,
    ));
  });

  /* Returns a list of available API endpoints and their parameters. */
  $app->options('/', 'apiRequest', function() use($app) {
    $response = array(
      'commands' => array(
        '/api' => 'Lists available generators',
        '/api/:name' => 'Returns text generated by the supplied generator name.',
        '/api/:name/grammar' => 'Returns the grammar definition file for the supplied generator name.',
      ),
    );
    $app->render(200, $response);
  });

  /* Returns actual output from a generator. */
  $app->get('/:name', 'apiRequest', function($name) use($app) {
    $time_start = microtime(true);
    $output = invokeGenerator($name);
    $time_end = microtime(true);
    $app->render(200, array(
      'output' => $output,
      'elapsed' => $time_end - $time_start
    ));
  })->conditions(\Slim\Route::getDefaultConditions());

  /* Returns the main grammar definition for a generator. */
  $app->get('/:name/grammar', 'apiRequest', function($name) use($app) {
    $filename = "generators/" . $name . "/main.rm";
    $definition = file_get_contents($filename);
    $app->render(200, array(
      'grammar' => $definition,
    ));
  })->conditions(\Slim\Route::getDefaultConditions());

});

function apiRequest() {
  $app = \Slim\Slim::getInstance();
  $app->view(new \JsonApiView());
  $app->add(new \JsonApiMiddleware());
}

function pageRequest() {
  $app = \Slim\Slim::getInstance();
  $view = new \Slim\Views\Twig();
  $app->view($view);
}

$app->run();
