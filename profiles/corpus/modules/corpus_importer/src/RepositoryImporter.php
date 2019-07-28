<?php

namespace Drupal\corpus_importer;

use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use writecrow\TagConverter\TagConverter;
use writecrow\LoremGutenberg\LoremGutenberg;
use writecrow\CountryCodeConverter\CountryCodeConverter;

/**
 * Class RepositoryImporter.
 *
 * @package Drupal\corpus_importer
 */
class RepositoryImporter extends ImporterService {

  /**
   * Helper function to save repository data.
   */
  public static function saveRepositoryNode($text, $repository_candidates, $options = []) {
    // First check if we can find the file.
    $file = self::uploadRepositoryResource($text['full_path'], $repository_candidates);
    if (!$file) {
      return ['file not found' => 'Corresponding file not found for' . $text['filename']];
    }
    else {
      $path_parts = pathinfo($file->getFileUri());
      $text['File Type'] = $path_parts['extension'];
    }
    // The key *must* match what is provided in the original text file.
    $taxonomies = [
      'Assignment' => 'assignment',
      'Course' => 'course',
      'Mode' => 'mode',
      'Length' => 'course_length',
      'Institution' => 'institution',
      'Instructor' => 'instructor',
      'Document Type' => 'document_type',
      'Course Semester' => 'semester',
      'Course Year' => 'year',
      'File Type' => 'file_type',
      'Topic' => 'topic',
    ];
    $fields = [];
    foreach ($taxonomies as $name => $machine_name) {
      $save = TRUE;
      if (in_array($name, array_keys($text))) {
        // Skip N/A values.
        if (in_array($text[$name], ['NA', 'N/A'])) {
          $save = FALSE;
          continue;
        }
        if ($machine_name == 'document_type') {
          $doc_code = $text['Document Type'];
          $text['Document Type'] = self::$docTypes[$doc_code];
        }
        if ($machine_name == 'assignment') {
          $assignment_code = $text['Assignment'];
          $text['Assignment'] = self::$assignments[$assignment_code];
        }
        if (in_array($machine_name, ['topic'])) {
          if (is_string($text[$name])) {
            $multiples = preg_split("/\s?;\s?/", $text[$name]);
            if (isset($multiples[1])) {
              array_push($multiples, $text[$name]);
            }
            $text[$name] = $multiples;
          }
        }
        $tid = self::getTidByName($text[$name], $machine_name);
        if ($tid == 0) {
          self::createTerm($text[$name], $machine_name);
          $tid = self::getTidByName($text[$name], $machine_name);
        }
      }
      else {
        $save = FALSE;
      }
      if ($save) {
        $fields[$machine_name] = $tid;
      }
    }
    if (isset($options['lorem']) && $options['lorem']) {
      $text['text'] = LoremGutenberg::generate(['sentences' => 10]);
    }
    if (isset($options['merge']) && $options['merge']) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['title' => $text['File ID']]);
      // Default to first instance found, in the unlikely event that there
      // are more than one.
      $node = reset($nodes);
      $return = 'updated';
    }
    if (!$node) {
      // Instantiate a new node object.
      $node = Node::create(['type' => 'resource']);
      $return = 'created';
    }
    $node->set('title', $text['File ID']);
    $node->set('field_file', ['target_id' => $file->id()]);
    $node->set('field_filename', ['value' => $text['filename']]);
    foreach ($taxonomies as $name => $machine_name) {
      if (!empty($fields[$machine_name])) {
        $node->set('field_' . $machine_name, ['target_id' => $fields[$machine_name]]);
      }
    }

    $body = trim(html_entity_decode($text['text']));
    // Remove unnecessary <End Header> text.
    $body = str_replace('<End Header>', '', $body);
    $node->set('field_raw_text', ['value' => $body, 'format' => 'plain_text']);
    $node->save();
    // Send back metadata on what happened.
    return [$return => $text['filename']];
  }

  /**
   * Utility: save file to backend.
   */
  public static function uploadRepositoryResource($full_path, $repository_candidates) {
    $path_parts = pathinfo($full_path);
    if (in_array($path_parts['filename'], array_keys($repository_candidates))) {
      $glob = glob($repository_candidates[$path_parts['filename']]);
    }
    else {
      $path_parts['dirname'] = str_replace('/Text/', '/Original/', $path_parts['dirname']);
      $original_wildcard = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.*';
      $glob = glob($original_wildcard);
    }
    if (!empty($glob[0])) {
      $original_file = $glob[0];
      print_r("Importing original file " . $original_file . PHP_EOL);
      $original_parts = pathinfo($original_file);
      $file = File::create([
        'uid' => 1,
        'filename' => $original_parts['basename'],
        'uri' => 'public://resources/' . $original_parts['basename'],
        'status' => 1,
      ]);
      $file->save();
      $file_content = file_get_contents($original_file);
      $directory = 'public://resources/';
      file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
      $file_saved = file_save_data($file_content, $directory . basename($original_file), FILE_EXISTS_REPLACE);
      return $file;
    }
    else {
      print_r('File not found! ' . $original_wildcard);
    }
    return FALSE;
  }

}
