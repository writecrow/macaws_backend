<?php

namespace Drupal\corpus_api_texts\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\corpus_api_texts\Sentence;
use Drupal\corpus_api_texts\Kwic;

/**
 * A handler to provide a field that is completely custom by the administrator.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("concordance_views_field")
 */
class ConcordanceViewsField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do nothing -- to override the parent query.
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['hide_alter_empty'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $param = \Drupal::request()->query->all();
    $entity = $values->_entity;
    $text_object = $entity->get('field_body')->getValue();
    $text = $text_object[0]['value'];
    $output = '';
    if (isset($param['search'])) {
      preg_match_all("/\"([^\"]+)\"/u", $param['search'], $phrases);
      $excerpt = Kwic::excerpt($text, $param['search']);
      $output .= '<table>';
      foreach ($excerpt as $line) {
        $output .= '<tr><td>' . $line . '</td></tr>';
      }
      $output .= '</table>';
    }
    return $output;
  }

}
