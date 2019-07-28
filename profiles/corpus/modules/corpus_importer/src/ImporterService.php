<?php

namespace Drupal\corpus_importer;

use Drupal\taxonomy\Entity\Term;
use writecrow\TagConverter\TagConverter;

/**
 * Class ImporterService.
 *
 * @package Drupal\corpus_importer
 */
class ImporterService {

  public static $assignments = [
    "AB" => "Annotated Bibliography",
    "AR" => "Argumentative Paper",
    "CA" => "Controversy Analysis",
    "CS" => "Case Study",
    "DE" => "Description and Explanation",
    "FA" => "Film Analysis",
    "GA" => "Genre Analysis",
    "GR" => "Genre Redesign",
    "IR" => "Interview Report",
    "LN" => "Literacy Narrative",
    "LR" => "Literature Review",
    "ME" => "Memo",
    "NR" => "Narrative",
    "OL" => "Open Letter",
    "PA" => "Public Argument",
    "PO" => "Portfolio",
    "PR" => "Profile",
    "PS" => "Position Argument",
    "RA" => "Rhetorical Analysis",
    "RE" => "Response",
    "RF" => "Reflection/Portfolio",
    "RR" => "Register Rewrite",
    "RP" => "Research Proposal",
    "SR" => "Summary and Response",
    "SY" => "Synthesis",
    "TA" => "Text Analysis",
    "VA" => "Variation Analysis",
  ];

  public static $docTypes = [
    "AC" => "Activity",
    "SL" => "Syllabus",
    "LP" => "Lesson Plan",
    "AS" => "Assignment Sheet/Prompt",
    "RU" => "Rubric",
    "PF" => "Peer Review Form",
    "QZ" => "Quiz",
    "HO" => "Handout",
    "SM" => "Supporting Material",
    "SP" => "Sample Paper",
    "HD" => "Handout",
    "NA" => "Not specific to any major assignment",
  ];

  public static $countryFixes = [
    'CHI' => 'CHN',
    'MLY' => 'MYS',
    'LEB' => 'LBN',
    'TKY' => 'TUR',
    'BRZ' => 'BRA',
    'SDA' => 'SAU',
  ];

  public static $draftFixes = [
    'D1' => '1',
    'D2' => '2',
    'D3' => '3',
    'D4' => '4',
    'DF' => 'F',
  ];

  public static $courseFixes = [
    '106' => 'ENGL 106',
    '107' => 'ENGL 107',
    '108' => 'ENGL 108',
  ];

  public static $collegeSpecific = [
    'Purdue University' => [
      'US' => 'Exploratory Studies',
      'E' => 'First Year Engineering',
      'M' => 'School of Management',
    ],
    'University of Arizona' => [
      'US' => 'College of Letters Arts & Sciences',
      'E' => 'College of Engineering',
      'M' => 'Eller College of Management',
    ],
  ];

  public static $collegeGeneral = [
    'EU' => 'College of Education',
    'A' => 'College of Agriculture',
    'HH' => 'College of Health & Human Sci',
    'LA' => 'College of Liberal Arts',
    'PC' => 'College of Pharmacy',
    'S' => 'College of Science',
    'PI' => 'Polytechnic Institute',
    'T' => 'Polytechnic Institute',
    'PP' => 'Pre-Pharmacy',
    'CH' => 'School of Chemical Engineering',
    'EC' => 'School of Electrical & Computer Engineering',
    'ME' => 'School of Mechanical Engineering',
    'CFA' => 'College of Fine Arts',
    'SBS' => 'College of Social & Behavioral Sciences',
    'COH' => 'College of Humanities',
    'NUR' => 'College of Nursing',
    'APL' => 'College of Architecture, Planning, & Landscape',
    'MED' => 'College of Medicine',
  ];

  /**
   * Main method: execute parsing and saving of redirects.
   *
   * @param mixed $files
   *   Simple array of filepaths.
   * @param string $options
   *   User-supplied default flags.
   */
  public static function import($files, $options = []) {

    if (PHP_SAPI == 'cli' && function_exists('drush_main')) {
      ini_set("memory_limit", "4096M");
      $paths = array_slice(scandir($files), 2);
      $absolute_paths = [];
      $repository_candidates = [];
      $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($files));
      foreach ($objects as $filepath => $object) {
        if (stripos($filepath, '.txt') !== FALSE) {
          $absolute_paths[]['tmppath'] = $filepath;
        }
        if (stripos($filepath, '.txt') === FALSE) {
          $path_parts = pathinfo($filepath);
          // Get a filelist of repository materials eligible for upload.
          $repository_candidates[$path_parts['filename']] = $filepath;
        }
      }
      $texts = self::convert($absolute_paths);

      foreach ($texts as $text) {
        // Fix failures in corpus headers:
        if (empty($text['Institution'])) {
          $text['Institution'] = 'Purdue University';
        }
        if ($text['type'] == 'corpus') {
          $result = CorpusImporter::saveCorpusNode($text, $options);
        }
        if ($text['type'] == 'repository') {
          $result = RepositoryImporter::saveRepositoryNode($text, $repository_candidates, $options);
        }
        if (isset($result['created'])) {
          echo $result['created'] . PHP_EOL;
        }
      }
    }
    else {
      // Convert files into machine-readable array.
      $texts = self::convert($files);
      drupal_set_message(count($files) . ' files found.');

      // Perform validation logic on each row.
      $texts = array_filter($texts, ['self', 'preSave']);

      // Save valid texts.
      foreach ($texts as $text) {
        $operations[] = [
          ['\Drupal\corpus_importer\ImporterService', 'save'],
          [$text, $options],
        ];
      }

      $batch = [
        'title' => t('Saving Texts'),
        'operations' => $operations,
        'finished' => ['\Drupal\corpus_importer\ImporterService', 'finish'],
        'file' => drupal_get_path('module', 'corpus_importer') . '/corpus_importer.module',
      ];

      batch_set($batch);
    }
  }

  /**
   * Convert tagged file into readable PHP array.
   *
   * @param mixed $files
   *   Simple array of filepaths.
   *
   * @return mixed[]
   *   Converted texts, in array format.
   */
  protected static function convert($files) {
    $data = [];
    foreach ($files as $uploaded_file) {
      $file = file_get_contents($uploaded_file['tmppath']);
      $text = TagConverter::php($file);
      $text['filename'] = basename($uploaded_file['tmppath'], '.txt');
      if (isset($text['Student ID'])) {
        $text['type'] = 'corpus';
        $data[] = $text;
      }
      elseif (isset($text['File ID'])) {
        $text['type'] = 'repository';
        $text['full_path'] = $uploaded_file['tmppath'];
        $data[] = $text;
      }
      elseif (isset($text['ID'])) {
        // Assume that files with "ID" are corpus files.
        $text['Student ID'] = $text['ID'];
        $text['type'] = 'corpus';
        $data[] = $text;
      }
    }

    return $data;
  }

  /**
   * Check for problematic data and remove or clean up.
   *
   * @param str[] $text
   *   Keyed array of texts.
   *
   * @return bool
   *   A TRUE/FALSE value to be used by array_filter.
   */
  public static function preSave(array $text) {
    return TRUE;
  }

  /**
   * Save an individual entity.
   *
   * @param str[] $text
   *   Keyed array of redirects, in the format
   *    [source, redirect, status_code, language].
   * @param str[] $options
   *   A 1 indicates that existing entities should be updated.
   * @param str[] $context
   *   Operational context for batch processes.
   */
  public static function save(array $text, array $options, array &$context) {
    if (isset($text['Student ID'])) {
      $result = CorpusImporter::saveCorpusNode($text, $options);
    }
    if (isset($text['File ID'])) {
      $result = RepositoryImporter::saveRepositoryNode($text, $options);
    }
    $key = key($result);
    $context['results'][$key][] = $result[$key];
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
