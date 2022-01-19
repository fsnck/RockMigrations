<?php namespace ProcessWire;

use DirectoryIterator;
use RockMigrations\RecorderFile;
use RockMigrations\WatchFile;
use RockMigrations\WireArrayDump;
use RockMigrations\YAML;

/**
 * @author Bernhard Baumrock, 19.01.2022
 * @license COMMERCIAL DO NOT DISTRIBUTE
 * @link https://www.baumrock.com
 */
class RockMigrations extends WireData implements Module, ConfigurableModule {

  const cachename = 'rockmigrations-last-run';

  /**
   * Timestamp of last run migration
   * @var int
   **/
  private $lastrun;

  /** @var string */
  public $path;

  /** @var WireArrayDump */
  private $recorders;

  /** @var WireArrayDump */
  private $watchlist;

  /** @var YAML */
  private $yaml;

  public static function getModuleInfo() {
    return [
      'title' => 'RockMigrations',
      'version' => '0.0.8',
      'summary' => 'Brings easy Migrations/GIT support to ProcessWire',
      'autoload' => 2,
      'singular' => true,
      'icon' => 'magic',
      'requires' => [],
      'installs' => [],
    ];
  }

  public function __construct() {
    parent::__construct();
    $this->path = $this->wire->config->paths($this);
    require_once($this->path."WireArrayDump.php");
    $this->recorders = $this->wire(new WireArrayDump());
    $this->watchlist = $this->wire(new WireArrayDump());
    $this->lastrun = $this->wire->cache->get(self::cachename);
    $this->watch($this->wire->config->paths->site."migrate");
  }

  public function init() {
    $this->wire('rockmigrations', $this);
    $this->rm1(); // load RM1 (install it)
    $this->addRecorderHooks();
    $this->watchModules();

    $config = $this->wire->config;
    if($this->saveToProject) $this->record($config->paths->site."project.yaml");
    if($this->saveToMigrate) $this->record($config->paths->site."migrate.yaml");
  }

  public function ready() {
    $this->migrateWatchfiles();
  }

  /**
   * Proxy all failing method calls to this version of RM to RM1
   */
  public function __call($method, $args) {
    if(!method_exists($this, $method)) {
      $rm1 = $this->rm1();
      if($rm1) return $rm1->$method(...$args);
      return false;
    }
    return self::$method(...$args);
  }

  /**
   * Add hooks to field/template creation for recorder
   * @return void
   */
  public function addRecorderHooks() {
    $this->addHookAfter("Fields::saved", $this, "saveRecorders");
    $this->addHookAfter("Templates::saved", $this, "saveRecorders");
  }

  /**
   * Find migration file (tries all extensions)
   * @return string|false
   */
  public function file($path) {
    $path = Paths::normalizeSeparators(realpath($path));
    if(is_file($path)) return $path;
    foreach(['yaml', 'json', 'php'] as $ext) {
      if(is_file($f = "$path.$ext")) return $f;
    }
    return false;
  }

  /**
   * Get or set json data to file
   * @return mixed
   */
  public function json($path, $data = null) {
    if($data === null) return json_decode(file_get_contents($path));
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Get lastmodified timestamp of watchlist
   * @return int
   */
  public function lastmodified() {
    $last = 0;
    foreach($this->watchlist as $file) {
      $m = filemtime($file->path);
      if($m>$last) $last=$m;
    }
    return $last;
  }

  /**
   * Migrate data
   *
   * If the second parameter is FALSE we use proxy to $rm->migrateNew()
   * With version 1.0.0 the second parameter will be removed and only the new
   * method will stay!
   *
   * @return mixed
   */
  public function migrate(array $data, $vars = []) {
    if($vars === true) return $this->migrateNew($data);
    return $this->rm1()->migrate($data, $vars);
  }

  /**
   * New version of migrate()
   *
   * This method will be renamed to migrate() on version 1.0.0
   *
   * @return void
   */
  public function migrateNew($data) {
    // TODO: add new implementation of $rm->migrate() that works with exportData() arrays
  }

  /**
   * Run migrations of all watchfiles
   * @return void
   */
  public function migrateWatchfiles() {
    $lastmodified = $this->lastmodified();
    if($this->lastrun < $lastmodified) {
      $this->log('Running migrations...');
      $this->updateLastrun();
      // bd($this->watchlist);
      foreach($this->watchlist as $file) {
        if(!$file->migrate) continue;
        $migrate = $this->wire->files->render($file->path, $file->vars, [
          'allowedPaths' => [dirname($file->path)],
        ]);
        if(is_string($migrate)) $migrate = $this->yaml($migrate);
        if(is_array($migrate)) {
          $this->log("Migrating {$file->path}");
          $this->migrate($migrate);
        }
        else {
          $this->log("Skipping {$file->path} (no config)");
        }
      }
    }
  }

  /**
   * Record settings to file
   *
   * Usage:
   * $rm->record("/path/to/file.yaml");
   *
   * $rm->record("/path/to/file.json", ['type'=>'json']);
   *
   * @param string $path
   * @param array $options
   * @return void
   */
  public function record($path, $options = []) {
    require_once($this->path."RecorderFile.php");
    $data = $this->wire(new RecorderFile()); /** @var RecorderFile $data */
    $data->setArray([
      'path' => $path,
      'type' => 'yaml', // other options: php, json
      'system' => false, // dump system fields and templates?
    ]);
    $data->setArray($options);
    $this->recorders->add($data);
  }

  /**
   * Return old version of RockMigrations
   * Will be dropped with RM v1.0.0!
   * @return RockMigrations1
   */
  public function rm1() {
    return $this->wire->modules->get("RockMigrations1");
  }

  /**
   * Save config to recorder file
   * @return void
   */
  public function saveRecorders(HookEvent $event) {
    foreach($this->recorders as $recorder) {
      $path = $recorder->path;
      $type = strtolower($recorder->type);
      $this->log("Writing config to $path");

      $arr = [
        'fields' => [],
        'templates' => [],
      ];
      foreach($this->sort($event->wire->fields) as $field) {
        if($field->flags) continue;
        $arr['fields'][$field->name] = $field->getExportData();
        unset($arr['fields'][$field->name]['id']);
      }
      foreach($this->sort($event->wire->templates) as $template) {
        if($template->flags) continue;
        $arr['templates'][$template->name] = $template->getExportData();
        unset($arr['templates'][$template->name]['id']);
      }

      if($type == 'yaml') $this->yaml($path, $arr);
      if($type == 'json') $this->json($path, $arr);
    }
    $this->updateLastrun();
  }

  /**
   * Get sorted WireArray of fields
   * @return WireArray
   */
  public function sort($data) {
    $arr = $this->wire(new WireArray()); /** @var WireArray $arr */
    foreach($data as $item) $arr->add($item);
    return $arr->sort('name');
  }

  /**
   * Update last run timestamp
   * @return void
   */
  public function updateLastrun() {
    $this->wire->cache->save(self::cachename, time(), WireCache::expireNever);
  }

  /**
   * Add file to watchlist
   *
   * Usage:
   * $rm->watch(__FILE__);
   *
   * If you dont specify an extension it will watch all available extensions:
   * $rm->watch('/path/to/module'); // watches module.[yaml|json|php]
   *
   * Only watch the file but don't migrate it. This is useful if a migration
   * file depends on something else (like constants of a module). To make the
   * migrations run when the module changes you can add the module file to the
   * watchlist:
   * $rm->watch('/site/modules/MyModule.module.php', false);
   *
   * @param string $file Path to file to be watched for changes
   * @param bool $migrate Does the file return an array for $rm->migrate() ?
   * @param array $options Array of options
   * @return void
   */
  public function watch($file, $migrate = true, $options = []) {
    if(!$path = $this->file($file)) return;

    // setup variables array that will be passed to file->render()
    $vars = ['rm' => $this];
    if(array_key_exists('vars', $options)) {
      $vars = array_merge($vars, $options['vars']);
    }

    require_once($this->path."WatchFile.php");
    $data = $this->wire(new WatchFile()); /** @var WatchFile $data */
    $data->setArray([
      'path' => $path,
      'migrate' => $migrate,
      'vars' => $vars,
    ]);
    $this->watchlist->add($data);
  }

  /**
   * Watch module migration files
   * @return void
   */
  public function watchModules() {
    $path = $this->wire->config->paths->siteModules;
    foreach (new DirectoryIterator($path) as $fileInfo) {
      if(!$fileInfo->isDir()) continue;
      if($fileInfo->isDot()) continue;
      $name = $fileInfo->getFilename();
      $migrateFile = $fileInfo->getPath()."/$name/$name.migrate";
      $this->watch("$migrateFile.yaml");
      $this->watch("$migrateFile.json");
      $this->watch("$migrateFile.php");
    }
  }

  /**
   * Interface to the YAML class based on Spyc
   *
   * Get YAML instance:
   * $rm->yaml();
   *
   * Get array from YAML file
   * $rm->yaml('/path/to/file.yaml');
   *
   * Save data to file
   * $rm->yaml('/path/to/file.yaml', ['foo'=>'bar']);
   *
   * @return mixed
   */
  public function yaml($path = null, $data = null) {
    require_once('spyc/Spyc.php');
    require_once('YAML.php');
    $yaml = $this->yaml ?: new YAML();
    if($path AND $data===null) return $yaml->load($path);
    elseif($path AND $data!==null) return $yaml->save($path, $data);
    return $yaml;
  }


  /**
  * Config inputfields
  * @param InputfieldWrapper $inputfields
  */
  public function getModuleConfigInputfields($inputfields) {

    $inputfields->add([
      'name' => 'saveToProject',
      'type' => 'toggle',
      'label' => 'Save migration data to /site/project.yaml?',
      'value' => !!$this->saveToProject,
      'columnWidth' => 50,
      'description' => 'This file will NOT be watched for changes! Think of it as a read-only dump of your project config.',
    ]);
    $inputfields->add([
      'name' => 'saveToMigrate',
      'type' => 'toggle',
      'label' => 'Save migration data to /site/migrate.yaml?',
      'value' => !!$this->saveToMigrate,
      'columnWidth' => 50,
      'description' => 'This file will automatically be watched for changes! That means you can record changes and then edit migrate.yaml in your IDE and the changes will automatically be applied on the next reload.',
    ]);

    return $inputfields;
  }

  public function __debugInfo() {
    $lastrun = "never";
    if($this->lastrun) {
      $lastrun = date("Y-m-d H:i:s", $this->lastrun)." ({$this->lastrun})";
    }
    return [
      'Version' => $this->getModuleInfo()['version'],
      'lastrun' => $lastrun,
      'recorders' => $this->recorders,
      'watchlist' => $this->watchlist,
    ];
  }

}
