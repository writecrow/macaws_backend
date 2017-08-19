<?php

namespace Drupal\corpus_importer;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use markfullmer\TagConverter\TagConverter;
use writecrow\LoremGutenberg\LoremGutenberg;
use writecrow\CountryCodeConverter\CountryCodeConverter;

/**
 * Class ImporterService.
 *
 * @package Drupal\corpus_importer
 */
class ImporterService {

  /**
   * Main method: execute parsing and saving of redirects.
   *
   * @param mixed $files
   *    Simple array of filepaths.
   * @param string $options
   *    User-supplied default flags.
   */
  public static function import($files, $options = array()) {

    if (PHP_SAPI == 'cli' && function_exists('drush_main')) {
      ini_set("memory_limit", "2048M");
      $paths = array_slice(scandir($files), 2);
      $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($files));
      foreach ($objects as $name => $object) {
        if (strpos($name, '.txt') !== FALSE) {
          $absolute_paths[]['tmppath'] = $name;
        }
      }
      $texts = self::convert($absolute_paths);
      foreach ($texts as $text) {
        $result = self::saveNode($text, $options);
        echo $result['created'] . PHP_EOL;
      }
    }
    else {
      // Convert files into machine-readable array.
      $texts = self::convert($files);
      drupal_set_message(count($files) . ' files found.');

      // Perform validation logic on each row.
      $texts = array_filter($texts, array('self', 'preSave'));

      // Save valid texts.
      foreach ($texts as $text) {
        $operations[] = array(
          array('\Drupal\corpus_importer\ImporterService', 'save'),
          array($text, $options),
        );
      }

      $batch = array(
        'title' => t('Saving Texts'),
        'operations' => $operations,
        'finished' => array('\Drupal\corpus_importer\ImporterService', 'finish'),
        'file' => drupal_get_path('module', 'corpus_importer') . '/corpus_importer.module',
      );

      batch_set($batch);
    }
  }

  /**
   * Convert CSV file into readable PHP array.
   *
   * @param mixed $files
   *    Simple array of filepaths.
   *
   * @return mixed[]
   *    Converted texts, in array format.
   */
  protected static function convert($files) {
    $data = array();
    foreach ($files as $uploaded_file) {
      $file = file_get_contents($uploaded_file['tmppath']);
      $text = TagConverter::php($file);
      $text['filename'] = basename($uploaded_file['tmppath'], '.txt');
      if (isset($text['ID'])) {
        $data[] = $text;
      }
    }

    return $data;
  }

  /**
   * Check for problematic data and remove or clean up.
   *
   * @param str[] $text
   *    Keyed array of texts.
   *
   * @return bool
   *    A TRUE/FALSE value to be used by array_filter.
   */
  public static function preSave(array $text) {
    return TRUE;
  }

  /**
   * Save an individual entity.
   *
   * @param str[] $text
   *    Keyed array of redirects, in the format
   *    [source, redirect, status_code, language].
   * @param str[] $options
   *    A 1 indicates that existing entities should be updated.
   */
  public static function save(array $text, array $options, &$context) {
    if (isset($text['ID'])) {
      $result = self::saveNode($text, $options);
    }
    $key = key($result);
    $context['results'][$key][] = $result[$key];
  }

  /**
   * Helper function to save data.
   */
  public static function saveNode($text, $options = array()) {
    $taxonomies = array(
      'Assignment' => 'assignment',
      'Draft' => 'draft',
      'Semester in School' => 'semester_in_school',
      'Gender' => 'gender',
      'Program' => 'program',
      'College' => 'college',
      'Term writing' => 'term_writing',
      'Country' => 'country',
    );

    $fields = array();
    foreach ($taxonomies as $name => $machine_name) {
      if (in_array($name, array_keys($text))) {
        $tid = self::getTidByName($text[$name], $machine_name);
        if ($tid == 0) {
          if ($machine_name == 'country') {
            $text[$name] = CountryCodeConverter::convert($text[$name]);
          }
          self::createTerm($text[$name], $machine_name);
          $tid = self::getTidByName($text[$name], $machine_name);
        }
      }
      $fields[$machine_name] = $tid;
    }

    if (isset($options['lorem']) && $options['lorem']) {
      $text['text'] = LoremGutenberg::generate(array('sentences' => 10));
    }
    if (isset($options['merge']) && $options['merge']) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['title' => $text['ID']]);
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
    $node->set('title', $text['ID']);
    $node->set('field_draft', array('target_id' => $fields['draft']));
    $node->set('field_college', array('target_id' => $fields['college']));
    $node->set('field_gender', array('target_id' => $fields['gender']));
    $node->set('field_program', array('target_id' => $fields['program']));
    $node->set('field_assignment', array('target_id' => $fields['assignment']));
    $node->set('field_semester_in_school', array('target_id' => $fields['semester_in_school']));
    $node->set('field_term_writing', array('target_id' => $fields['term_writing']));
    $node->set('field_toefl_total', array('value' => $text['TOEFL-total']));
    $node->set('field_toefl_writing', array('value' => $text['TOEFL-writing']));
    $node->set('field_toefl_speaking', array('value' => $text['TOEFL-speaking']));
    $node->set('field_toefl_reading', array('value' => $text['TOEFL-reading']));
    $node->set('field_toefl_listening', array('value' => $text['TOEFL-listening']));
    $node->set('field_filename', array('value' => $text['filename']));
    $node->set('field_country', array('target_id' => $fields['country']));
    $node->set('field_text', array('value' => $text['text'], 'format' => 'plain_text'));
    $node->save();
    // Send back metadata on what happened.
    return array($return => $text['ID']);
  }

  /**
   * Utility: find term by name and vid.
   *
   * @param string $name
   *   Term name.
   * @param string $vid
   *   Term vid.
   *
   * @return int
   *   Term id or 0 if none.
   */
  protected static function getTidByName($name = NULL, $vid = NULL) {
    $properties = [];
    if (!empty($name)) {
      $properties['name'] = $name;
    }
    if (!empty($vid)) {
      $properties['vid'] = $vid;
    }
    $terms = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties($properties);
    $term = reset($terms);

    return !empty($term) ? $term->id() : 0;
  }

  /**
   * Helper function.
   */
  public static function createTerm($name, $taxonomy_type) {
    $term = Term::create([
      'name' => $name,
      'vid' => $taxonomy_type,
    ])->save();
    return TRUE;
  }

  /**
   * Batch API callback.
   */
  public static function finish($success, $results, $operations) {
    if (!$success) {
      $message = t('Finished, with possible errors.');
      drupal_set_message($message, 'warning');
    }
    if (isset($results['updated'])) {
      drupal_set_message(count($results['updated']) . ' texts updated.');
    }
    if (isset($results['created'])) {
      drupal_set_message(count($results['created']) . ' texts created.');
    }

  }

}
