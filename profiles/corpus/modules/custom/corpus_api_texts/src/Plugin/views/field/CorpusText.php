<?php

namespace Drupal\corpus_api_texts\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A handler to provide a field that is completely custom by the administrator.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("corpus_text")
 */
class CorpusText extends FieldPluginBase {

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
    $fulltext = [
      '106i_LR_2_IND_1_F_10256_PRD',
      '106i_LR_F_CHN_1_M_10044_PRD',
      '106i_AR_F_CHN_3_M_10946_PRD',
      '106i_AR_1_GTM_2_M_11001_PRD',
      '106i_AR_1_CHN_1_F_10131_PRD',
      '106i_RP_2_CHN_1_F_10503_PRD',
      '106i_LR_1_CHN_1_M_10677_PRD',
      '106i_LR_1_CHN_2_F_10987_PRD',
      '106i_RP_F_CHN_1_M_10165_PRD',
      '106i_IR_1_CHN_1_F_10041_PRD',
      '106i_LN_2_IND_1_M_10369_PRD',
    ];
    if (in_array($entity->getTitle(), $fulltext)) {
      return $text;
    }
    // Default to returning a truncated version of the text.
    if (strlen($text) > 600) {
      $output = '<p>Displaying first 600 characters. For fulltext, apply for an authenticated account.</p><hr />';
      $output .= preg_replace('/\s+?(\S+)?$/', '', substr($text, 0, 600)) . '...';
    }
    else {
      $output = $text;
    }
    return $output;
  }

}
