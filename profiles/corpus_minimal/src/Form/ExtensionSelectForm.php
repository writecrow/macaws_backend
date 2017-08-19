<?php

namespace Drupal\corpus_minimal\Form;

use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\corpus_minimal\Extender;
use Drupal\corpus_minimal\FormHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form for selecting which UTexas extensions to install.
 */
class ExtensionSelectForm extends FormBase {

  /**
   * The extender configuration object.
   *
   * @var \Drupal\corpus_minimal\Extender
   */
  protected $extender;

  /**
   * The Drupal application root.
   *
   * @var string
   */
  protected $root;

  /**
   * The info parser service.
   *
   * @var \Drupal\Core\Extension\InfoParserInterface
   */
  protected $infoParser;

  /**
   * The form helper.
   *
   * @var \Drupal\corpus_minimal\FormHelper
   */
  protected $formHelper;

  /**
   * ExtensionSelectForm constructor.
   *
   * @param \Drupal\corpus_minimal\Extender $extender
   *   The corpus_minimal extender configuration object.
   * @param string $root
   *   The Drupal application root.
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info parser service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translator
   *   The string translation service.
   * @param \Drupal\corpus_minimal\FormHelper $form_helper
   *   The form helper.
   */
  public function __construct(Extender $extender, $root, InfoParserInterface $info_parser, TranslationInterface $translator, FormHelper $form_helper) {
    $this->extender = $extender;
    $this->root = $root;
    $this->infoParser = $info_parser;
    $this->stringTranslation = $translator;
    $this->formHelper = $form_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('corpus_minimal.extender'),
      $container->get('app.root'),
      $container->get('info_parser'),
      $container->get('string_translation'),
      $container->get('corpus_minimal.form_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'corpus_minimal_select_extensions';
  }

  /**
   * Extracts a set of elements from an array by key.
   *
   * @param array $keys
   *   The keys to extract.
   * @param array $values
   *   The array from which to extract the elements.
   *
   * @return array
   *   The extracted elements.
   */
  protected function pluck(array $keys, array $values) {
    return array_intersect_key($values, array_combine($keys, $keys));
  }

  /**
   * Yields info for each of UTexas' extensions.
   */
  protected function getExtensionInfo() {
    $extension_discovery = new ExtensionDiscovery($this->root);

    $extensions = $this->pluck(
      [
        'corpus_core',
      ],
      $extension_discovery->scan('module')
    );

    /** @var \Drupal\Core\Extension\Extension $extension */
    foreach ($extensions as $key => $extension) {
      $info = $this->infoParser->parse($extension->getPathname());
      yield $key => $info;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array &$install_state = NULL) {
    $form['#title'] = $this->t('Custom Functionality');

    $form['help'] = [
      '#weight' => -1,
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
    $form['modules'] = [
      '#type' => 'checkboxes',
      '#weight' => 0,
    ];
    $form['experimental'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Experimental'),
      '#tree' => TRUE,
      '#weight' => 1,
    ];
    $form['experimental']['gate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand the <a href="@url" target="_blank">potential risks of experimental modules</a>', [
        '@url' => 'https://github.com/writecrow',
      ]),
    ];
    $form['experimental']['modules'] = [
      '#type' => 'checkboxes',
    ];
    $form['actions'] = [
      'continue' => [
        '#type' => 'submit',
        '#value' => $this->t('Continue'),
      ],
      '#type' => 'actions',
      '#weight' => 5,
    ];
    $form['sub_components'] = [
      '#type' => 'value',
      '#value' => [],
    ];

    $this->formHelper->applyStandardProcessing($form['modules']);
    $form['modules']['#process'][] = [__CLASS__, 'requireCore'];

    $this->formHelper->applyStandardProcessing($form['experimental']['modules']);
    $form['experimental']['modules']['#process'][] = [__CLASS__, 'addExperimentalGate'];

    foreach ($this->getExtensionInfo() as $key => $info) {
      if (empty($info['experimental'])) {
        $form['modules']['#options'][$key] = $info['name'];
        $form['modules']['#default_value'][] = $key;
      }
      else {
        $form['experimental']['modules']['#options'][$key] = $info['name'];
      }

      // Store the list sub-components to avoid re-parsing the info file.
      if (isset($info['components'])) {
        $form['sub_components']['#value'][$key] = $info['components'];
      }
    }

    // Hide the experimental section if there are no experimental extensions.
    $form['modules']['#access'] = isset($form['experimental']['modules']['#options']);

    // If the extender configuration has a pre-selected set of extensions, don't
    // allow the user to choose different ones.
    $chosen_ones = $this->extender->getExtensions();
    if (is_array($chosen_ones)) {
      // Prevent selection of non-experimental extensions.
      $form['modules']['#default_value'] = array_intersect(
        array_keys($form['modules']['#options']),
        $chosen_ones
      );
      $form['modules']['#disabled'] = TRUE;

      // Prevent selection of experimental extensions.
      $form['experimental']['modules']['#default_value'] = array_intersect(
        array_keys($form['experimental']['modules']['#options']),
        $chosen_ones
      );
      $form['experimental']['modules']['#disabled'] = TRUE;

      // Acknowledge the experimental gate.
      $form['experimental']['gate']['#disabled'] = TRUE;
      $form['experimental']['gate']['#default_value'] = TRUE;

      // Explain ourselves.
      $form['help']['#markup'] = $this->t("Corpus extensions have been pre-selected in the corpus_minimal.extend.yml file in your sites directory and are disabled here as a result.");
    }
    else {
      $form['help']['#markup'] = $this->t("Developers already familiar with the specific components may disable unneeded functionality, below. These components may also be uninstalled after the main installation, if desired.");
    }

    return $form;
  }

  /**
   * Forces the Corpus Core extension to be selected.
   *
   * Turns the UTexas Core checkbox into a persistent server-side value so
   * that it is always installed.
   *
   * @param array $element
   *   The set of checkboxes listing the available extensions.
   *
   * @return array
   *   The modified checkboxes.
   */
  public static function requireCore(array $element) {
    $element['corpus_core'] = [
      '#type' => 'value',
      '#value' => $element['corpus_core']['#return_value'],
    ];
    return $element;
  }

  /**
   * Process function to hide an element behind the experimental gate.
   *
   * @param array $element
   *   The element to process.
   *
   * @return array
   *   The processed element.
   */
  public static function addExperimentalGate(array $element) {
    // The element is only visible if the experimental gate is acknowledged.
    foreach (Element::children($element) as $key) {
      $element[$key]['#states']['visible']['#edit-experimental-gate']['checked'] = TRUE;
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $modules = $form_state->getValue('modules');

    $experimental = $form_state->getValue('experimental');
    // Only install the experimental modules if they have explicitly accepted
    // the potential risks.
    if ($experimental['gate']) {
      $modules = array_merge($modules, $experimental['modules']);
    }
    $modules = array_filter($modules);

    // Merge in sub-components of enabled extensions...
    $sub_components = $form_state->getValue('sub_components');
    foreach ($modules as $module) {
      if (isset($sub_components[$module])) {
        $modules = array_merge($modules, $sub_components[$module]);
      }
    }
    // ...except the ones excluded by the extender.
    $modules = array_diff($modules, $this->extender->getExcludedComponents());

    $GLOBALS['install_state']['corpus_minimal']['modules'] = array_merge($modules, $this->extender->getModules());
  }

}
