<?php

namespace Drupal\config_auto_export\Form;

use Drupal\config_auto_export\FileStorageFactory;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class Settings.
 */
class Settings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'config_auto_export.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_auto_export_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('config_auto_export.settings');
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory'),
      '#default_value' => $config->get('directory'),
    ];
    $form['webhook'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook'),
      '#default_value' => $config->get('webhook'),
      '#maxlength' => 1024,
    ];
    $form['webhook_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Params'),
      '#default_value' => $config->get('webhook_params'),
      '#attributes' => ['data-yaml-editor' => 'true'],
    ];
    $form['delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Delay in seconds'),
      '#default_value' => $config->get('delay'),
      '#description' => t('Set this to zero to export config changes without any delay. If you provide a value higher than that, the export will be triggered by the next feasible cron after that period.'),
      '#min' => 0,
    ];
    $form['delay_from_first'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Calculate dealy from first event'),
      '#default_value' => $config->get('delay_from_first'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    if ($form_state->getValue('directory') !== FileStorageFactory::getDirectory()) {
      FileStorageFactory::removeSync();
    }
    $this->config('config_auto_export.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('directory', $form_state->getValue('directory'))
      ->set('webhook', $form_state->getValue('webhook'))
      ->set('webhook_params', $form_state->getValue('webhook_params'))
      ->set('delay', $form_state->getValue('delay'))
      ->set('delay_from_first', $form_state->getValue('delay_from_first'))
      ->save();
  }

}
