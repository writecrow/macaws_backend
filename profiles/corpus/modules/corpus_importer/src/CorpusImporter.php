<?php

namespace Drupal\corpus_importer;

use Drupal\Component\Utility\Html;
use Drupal\node\Entity\Node;
use writecrow\LoremGutenberg\LoremGutenberg;
use writecrow\CountryCodeConverter\CountryCodeConverter;

/**
 * Class CorpusImporter.
 *
 * @package Drupal\corpus_importer
 */
class CorpusImporter extends ImporterService {

  /**
   * Helper function to save corpus data.
   */
  public static function saveCorpusNode($text, $options = []) {
    $vocabularies = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple();
    foreach ($vocabularies as $key => $vocabulary) {
      $taxonomies[$vocabulary->label()] = $vocabulary->id();
    }
    foreach ($taxonomies as $name => $machine_name) {
      $tid = '';
      $save = TRUE;
      if (in_array($name, array_keys($text)) || $name == "Grouped L1") {
        // Skip N/A values.
        if (in_array($text[$name], ['NA', 'N/A', 'No', 'Na', 'Nan', 'NaN'])) {
          $save = FALSE;
        }
        if ($name == 'Grouped L1') {
          $top_level_l1s = ["English", "Spanish", "Portuguese", "Russian"];
          if (!is_array($text['L1'])) {
            $grouped_l1s = [$text['L1']];
          }
          else {
            $grouped_l1s = $text['L1'];
          }
          foreach ($grouped_l1s as $lang) {
            if (!in_array($lang, $top_level_l1s)) {
              $text['Grouped L1'][] = 'Other';
            }
            else {
              $text['Grouped L1'][] = $lang;
            }
          }
        }
        if (in_array($machine_name, ['institution']) && empty($text['Institution'])) {
          $text['Institution'] = 'Purdue University';
        }
        if (in_array($machine_name, ['gender']) && $text['Gender'] == 'G') {
          $text['Gender'] = 'M';
        }
        if ($machine_name == 'assignment') {
          $assignment_code = $text['Assignment'];
          $text['Assignment'] = self::$assignments[$assignment_code];
        }
        if (in_array($machine_name, ['assignment_name', 'experience_abroad'])) {
          $r = preg_replace("/\([^)]+\)/", "", $text[$name]);
          $text[$name] = trim($r);
        }
        // Standardize draft names.
        if ($machine_name == 'draft') {
          if (in_array($text[$name], array_keys(self::$draftFixes))) {
            $code = $text[$name];
            $text[$name] = self::$draftFixes[$code];
          }
        }
        // Standardize course names.
        if ($machine_name == 'course') {
          if (in_array($text[$name], array_keys(self::$courseFixes))) {
            $code = $text[$name];
            $text[$name] = self::$courseFixes[$code];
          }
        }
        if ($machine_name == 'assignment_name') {
          $text[$name] = preg_replace('/(.*) - /', '$2', $text[$name]);
        }
        // Convert country IDs to readable names.
        if ($machine_name == 'country') {
          if (in_array($text[$name], array_keys(self::$countryFixes))) {
            $code = $text[$name];
            $text[$name] = self::$countryFixes[$code];
          }
          $text[$name] = CountryCodeConverter::convert($text[$name]);
        }
        if ($save) {
          if (is_array($text[$name])) {
            $tids = [];
            foreach ($text[$name] as $term) {
              if ($machine_name == 'college') {
                $instititution = $text['Institution'];
                if (in_array($term, array_keys(self::$collegeGeneral))) {
                  $term = self::$collegeGeneral[$term];
                }
                elseif (in_array($term, array_keys(self::$collegeSpecific[$instititution]))) {
                  $term = self::$collegeSpecific[$instititution][$term];
                }
                else {
                  continue;
                }
              }
              $tid = self::getTidByName($term, $machine_name);
              if ($tid == 0) {
                self::createTerm($term, $machine_name);
                $tid = self::getTidByName($term, $machine_name);
              }
              $tids[] = $tid;
            }
            $fields[$machine_name] = $tids;
          }
          else {
            $tid = self::getTidByName($text[$name], $machine_name);
            if ($tid == 0 && isset($text[$name])) {
              self::createTerm($text[$name], $machine_name);
              $tid = self::getTidByName($text[$name], $machine_name);
            }
            if ($tid != 0) {
              $fields[$machine_name] = $tid;
            }
          }
        }
      }
    }
    if (isset($options['lorem']) && $options['lorem']) {
      $text['text'] = LoremGutenberg::generate(['sentences' => 10]);
    }
    if (isset($options['merge']) && $options['merge']) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['title' => $text['filename']]);
      // Default to first instance found, in the unlikely event that there
      // are more than one.
      $node = reset($nodes);
      $return = 'updated';
    }
    if (!$node) {
      // Instantiate a new node object.
      $node = Node::create(['type' => 'text']);
      $return = 'created';
    }
    $node->set('title', $text['filename']);
    foreach ($taxonomies as $name => $machine_name) {
      $field_name = substr('field_' . $machine_name, 0, 32);
      if (!$node->hasField($field_name)) {
        continue;
      }
      if (!empty($fields[$machine_name])) {
        if (is_array($fields[$machine_name])) {
          $elements = [];
          foreach ($fields[$machine_name] as $delta => $term) {
            $elements[] = ['delta' => $delta, 'target_id' => $term];
          }
          $node->set($field_name, $elements);
        }
        else {
          $node->set($field_name, ['target_id' => $fields[$machine_name]]);
        }
      }
    }

    $body = trim(html_entity_decode($text['text']));
    // Remove unnecessary <End Header> text.
    $body = str_replace('<End Header>', '', $body);
    $node->set('field_text', ['value' => $body, 'format' => 'plain_text']);

    $clean = Html::escape(strip_tags($body));
    $node->set('field_wordcount', ['value' => self::wordCountUtf8($clean)]);

    $node->save();
    // Send back metadata on what happened.
    return [$return => $text['filename']];
  }

  /**
   * Perform word counting for UTF8.
   */
  public static function wordCountUtf8($str) {
    // https://php.net/str_word_count#107363.
    return count(preg_split('~[^\p{L}\p{N}\']+~u', $str));
  }

}
