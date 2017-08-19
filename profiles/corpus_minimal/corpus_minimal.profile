<?php

/**
 * @file
 * Defines the Corpus Minimal install screen by modifying the install form.
 */

use Drupal\corpus_minimal\Form\ExtensionSelectForm;

/**
 * Implements hook_install_tasks().
 */
function corpus_minimal_install_tasks() {
  return array(
    'corpus_minimal_select_extensions' => array(
      'display_name' => t('Choose extensions'),
      'display' => TRUE,
      'type' => 'form',
      'function' => ExtensionSelectForm::class,
    ),
    'corpus_minimal_install_extensions' => array(
      'display_name' => t('Install extensions'),
      'display' => TRUE,
      'type' => 'batch',
    ),
  );
}

/**
 * Implements hook_install_tasks_alter().
 */
function corpus_minimal_install_tasks_alter(array &$tasks, array $install_state) {
  $tasks['install_finished']['function'] = 'corpus_minimal_post_install_redirect';
}

/**
 * Install task callback; prepares a batch job for corpus_minimal extensions.
 *
 * @param array $install_state
 *   The current install state.
 *
 * @return array
 *   The batch job definition.
 */
function corpus_minimal_install_extensions(array &$install_state) {
  $batch = array();
  foreach ($install_state['corpus_minimal']['modules'] as $module) {
    $batch['operations'][] = ['corpus_minimal_install_module', (array) $module];
  }
  return $batch;
}

/**
 * Batch API callback. Installs a module.
 *
 * @param string|array $module
 *   The name(s) of the module(s) to install.
 */
function corpus_minimal_install_module($module) {
  \Drupal::service('module_installer')->install((array) $module);
}

/**
 * Redirects the user to a particular URL after installation.
 *
 * @param array $install_state
 *   The current install state.
 *
 * @return array
 *   A renderable array with a success message and a redirect header, if the
 *   extender is configured with one.
 */
function corpus_minimal_post_install_redirect(array &$install_state) {
  $redirect = \Drupal::service('corpus_minimal.extender')->getRedirect();

  $output = [
    '#title' => t('Ready to research'),
    'info' => [
      '#markup' => t('Congratulations, you installed your Corpus website! If you are not redirected in 5 seconds, <a href="@url">click here</a> to proceed to your site.', [
        '@url' => $redirect,
      ]),
    ],
    '#attached' => [
      'http_header' => [
        ['Cache-Control', 'no-cache'],
      ],
    ],
  ];

  // The installer doesn't make it easy (possible?) to return a redirect
  // response, so set a redirection META tag in the output.
  $meta_redirect = [
    '#tag' => 'meta',
    '#attributes' => [
      'http-equiv' => 'refresh',
      'content' => '0;url=' . $redirect,
    ],
  ];
  $output['#attached']['html_head'][] = [$meta_redirect, 'meta_redirect'];

  return $output;
}
