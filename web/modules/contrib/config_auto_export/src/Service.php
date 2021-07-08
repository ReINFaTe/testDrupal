<?php

namespace Drupal\config_auto_export;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\GuzzleException;

class lsService {

  public const STATE_KEY_DUE_TIMESTAMP = 'config_auto_export.due_next.timestamp';

  /** @var \Drupal\Core\Config\ImmutableConfig */
  protected $config;

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var \Drupal\Component\Datetime\Time
   */
  protected $time;

  /**
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $client;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Service constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Component\Datetime\Time $time
   * @param \Drupal\Core\Http\ClientFactory $client
   */
  public function __construct(ConfigFactoryInterface $config_factory, FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_channel_factory, StateInterface $state, Time $time, ClientFactory $client) {
    $this->config = $config_factory->get('config_auto_export.settings');
    $this->fileSystem = $file_system;
    $this->logger = $logger_channel_factory->get('config');
    $this->state = $state;
    $this->time = $time;
    $this->client = $client;
  }

  /**
   * Trigger the webhook.
   */
  public function triggerExport(): void {
    $webhook = $this->config->get('webhook');
    if (empty($webhook)) {
      return;
    }
    $exportPath = $this->fileSystem->realpath($this->config->get('directory'));
    $configPath = $this->fileSystem->realpath(Settings::get('config_sync_directory'));
    $data = [
      'form_params' => Yaml::decode(str_replace(
        ['[export directory]', '[config directory]'],
        [$exportPath, $configPath],
        $this->config->get('webhook_params'))),
    ];

    try {
      $client = $this->client->fromOptions(['base_uri' => $webhook]);
      $client->request('post', '', $data);
    }
    catch (GuzzleException $e) {
      $this->logger->critical('Trigger for config auto export failed: {msg}', ['msg' => $e->getMessage()]);
    }
  }

  /**
   * Check if a trigger due date exists and is due, then call the trigger.
   */
  public function checkDueDate(): void {
    if (($dueTime = $this->state->get(self::STATE_KEY_DUE_TIMESTAMP)) && $dueTime <= $this->time->getRequestTime()) {
      $this->triggerExport();
      $this->state->delete(self::STATE_KEY_DUE_TIMESTAMP);
    }
  }

}
