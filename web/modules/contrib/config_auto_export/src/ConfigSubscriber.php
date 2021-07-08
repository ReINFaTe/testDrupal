<?php

namespace Drupal\config_auto_export;

use Drupal\Component\Datetime\Time;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\language\Config\LanguageConfigOverrideCrudEvent;
use Drupal\language\Config\LanguageConfigOverrideEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Config subscriber.
 */
class ConfigSubscriber implements EventSubscriberInterface, DestructableInterface {

  /** @var \Drupal\Core\Config\ImmutableConfig */
  protected $config;

  /** @var \Drupal\Core\Config\CachedStorage */
  protected $configCache;

  /** @var \Drupal\Core\Config\FileStorage */
  protected $configStorage;

  /** @var array */
  protected $configSplitFiles;

  /** @var array */
  protected $configSplitModules;

  /** @var bool */
  protected $active = TRUE;

  /** @var bool */
  protected $triggerNeeded = FALSE;

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var \Drupal\Component\Datetime\Time
   */
  protected $time;

  /**
   * @var \Drupal\config_auto_export\Service
   */
  protected $service;

  /**
   * Constructs a new Settings object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Config\CachedStorage $config_cache
   * @param \Drupal\Core\Config\FileStorage $config_storage
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Component\Datetime\Time $time
   * @param \Drupal\config_auto_export\Service $service
   */
  public function __construct(ConfigFactoryInterface $config_factory, CachedStorage $config_cache, FileStorage $config_storage, FileSystemInterface $file_system, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager, StateInterface $state, Time $time, Service $service) {
    $this->config = $config_factory->get('config_auto_export.settings');
    $this->configCache = $config_cache;
    $this->configStorage = $config_storage;
    $this->fileSystem = $file_system;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->time = $time;
    $this->service = $service;
  }

  /**
   * @return bool
   *   Protected function enabled.
   */
  protected function enabled(): bool {
    static $enabled;

    if (!isset($enabled)) {
      $enabled = FALSE;
      if ($this->config->get('enabled')) {
        $uri = $this->config->get('directory');
        if ($this->fileSystem->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
          if (is_writable($uri) || @chmod($uri, 0777)) {
            $enabled = TRUE;
          }
        }
      }
    }

    return $enabled;
  }

  /**
   * Read all config files from config splits, if available.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function readConfigSplitFiles(): void {
    $this->configSplitFiles = [];
    $this->configSplitModules = [];
    if (!$this->moduleHandler->moduleExists('config_split')) {
      return;
    }
    $extension = '.yml';
    $regex = '/' . str_replace('.', '\.', $extension) . '$/';
    /** @var \Drupal\config_split\Entity\ConfigSplitEntityInterface $split */
    foreach ($this->entityTypeManager->getStorage('config_split')->loadMultiple() as $split) {
      $this->configSplitModules += $split->get('module');
      /** @noinspection AdditionOperationOnArraysInspection */
      $this->configSplitFiles += $this->fileSystem->scanDirectory($split->get('folder'), $regex, ['key' => 'filename']);
    }
    ksort($this->configSplitModules);
    ksort($this->configSplitFiles);
  }

  /**
   * @param string $name
   *
   * @return bool
   */
  protected function existsInConfigSplit($name): bool {
    if (!isset($this->configSplitFiles)) {
      try {
        $this->readConfigSplitFiles();
      } catch (InvalidPluginDefinitionException $e) {
      } catch (PluginNotFoundException $e) {
      }
    }
    return isset($this->configSplitFiles[$name . '.yml']);
  }

  /**
   * Saves changed config to a configurable directory.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   Public function onConfigSave event.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($this->active && $this->enabled()) {
      $name = $event->getConfig()->getName();
      if ($this->existsInConfigSplit($name)) {
        return;
      }
      // Read config that will be exported.
      $data = $this->configCache->read($name);
      // Was a module enabled/disabled?
      if ($name === 'core.extension' && !empty($this->configSplitModules)) {
        // Iterate over config split modules.
        foreach ($this->configSplitModules as $splitModule => $value) {
          // Is a "split-enabled" module in the module list?
          if (array_key_exists($splitModule, $data['module'])) {
            // Remove split module from module list.
            unset($data['module'][$splitModule]);
          }
        }
      }
      $this->configStorage->write($name, $data);
      $this->triggerNeeded = TRUE;
    }
  }

  /**
   * Saves changed config translation to a configurable directory.
   *
   * @param \Drupal\language\Config\LanguageConfigOverrideCrudEvent $event
   *   Public function onConfigTranslationSave event.
   */
  public function onConfigTranslationSave(LanguageConfigOverrideCrudEvent $event): void {
    if ($this->active && $this->enabled()) {
      $object = $event->getLanguageConfigOverride();
      $configLanguageStorage = $this->configStorage->createCollection('language.' . $object->getLangcode());
      $configLanguageStorage->write($object->getName(), $object->get());
      $this->triggerNeeded = TRUE;
    }
  }

  /**
   * Turn off this subscriber on importing configuration.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   Public function onConfigImportValidate event.
   *
   * @noinspection PhpUnusedParameterInspection*/
  public function onConfigImportValidate(ConfigImporterEvent $event): void {
    $this->active = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[ConfigEvents::SAVE][] = ['onConfigSave', 0];
    $events[ConfigEvents::IMPORT_VALIDATE][] = ['onConfigImportValidate', 1024];
    if (class_exists(LanguageConfigOverrideEvents::class)) {
      $events[LanguageConfigOverrideEvents::SAVE_OVERRIDE][] = ['onConfigTranslationSave', 0];
    }
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    if ($this->triggerNeeded) {
      if ($delay = $this->config->get('delay')) {
        if (!$this->config->get('delay_from_first') || !$this->state->get($this->service::STATE_KEY_DUE_TIMESTAMP)) {
          $this->state->set($this->service::STATE_KEY_DUE_TIMESTAMP, $this->time->getRequestTime() + $delay);
        }
      }
      else {
        $this->service->triggerExport();
      }
    }
  }

}
