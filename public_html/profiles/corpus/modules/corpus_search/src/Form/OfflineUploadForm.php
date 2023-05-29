<?php

namespace Drupal\corpus_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Allow authorized users to upload a single zip file of the corpus.
 *
 * @package Drupal\corpus_search\Form
 */
class OfflineUploadForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'corpus_search.offline_upload_form',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'offline_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $portuguese = \Drupal::state()->get('offline_fid_portuguese');
    if (!$portuguese) {
      $portuguese = 0;
    }
    $russian = \Drupal::state()->get('offline_fid_russian');
    if (!$russian) {
      $russian = 0;
    }
    $form['portuguese'] = [
      '#type' => 'managed_file',
      '#upload_location' => 'private://',
      '#multiple' => FALSE,
      '#description' => $this->t('Allowed extensions: zip'),
      '#upload_validators' => [
        'file_validate_extensions' => ['zip'],
      ],
      '#default_value' => [$portuguese],
      '#title' => $this->t('Portuguese offline corpus'),
    ];
    $form['russian'] = [
      '#type' => 'managed_file',
      '#upload_location' => 'private://',
      '#multiple' => FALSE,
      '#description' => $this->t('Allowed extensions: zip'),
      '#upload_validators' => [
        'file_validate_extensions' => ['zip'],
      ],
      '#default_value' => [$russian],
      '#title' => $this->t('Russian offline corpus'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save uploaded file to system'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $field = $form_state->getValue('portuguese');
    if (!$field[0]) {
      $form_state->setErrorByName('portuguese', $this->t("Upload failed. Please try again."));
    }
    $field = $form_state->getValue('russian');
    if (!$field[0]) {
      $form_state->setErrorByName('russian', $this->t("Upload failed. Please try again."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $field = $form_state->getValue('portuguese');
    $portuguese = File::load($field[0]);
    // This will set the file status to 'permanent' automatically.
    \Drupal::service('file.usage')->add($portuguese, 'corpus_search', 'file', $portuguese->id());

    \Drupal::state()->set('offline_fid_portuguese', $portuguese->id());

    $field = $form_state->getValue('russian');
    $russian = File::load($field[0]);
    // This will set the file status to 'permanent' automatically.
    \Drupal::service('file.usage')->add($russian, 'corpus_search', 'file', $russian->id());

    \Drupal::state()->set('offline_fid_russian', $russian->id());
  }

}
