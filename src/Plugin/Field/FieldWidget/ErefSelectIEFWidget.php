<?php

namespace Drupal\eref_select_ief\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Tags;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\inline_entity_form\Element\InlineEntityForm;
use Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex;
use Drupal\inline_entity_form\TranslationHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'options_select' widget.
 *
 * @FieldWidget(
 *   id = "eref_select_ief",
 *   label = @Translation("Select list with IEF"),
 *   field_types = {
 *     "entity_reference"
 *   },
 *   multiple_values = TRUE
 * )
 */
class ErefSelectIEFWidget extends OptionsSelectWidget implements ContainerFactoryPluginInterface
{
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /** @var InlineEntityFormComplex $widget */
  protected $iefcWidget;
  /**
   * @var string
   */
  protected $iefId;

  /**
   * Constructs a InlineEntityFormComplex object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager)
  {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->iefcWidget = \Drupal::service('plugin.manager.field.widget')->getInstance([
      'field_definition' => $this->fieldDefinition,
      'form_mode' => 'default',
      'prepare' => FALSE,
      'configuration' => [
        'type' => 'inline_entity_form_complex',
        'settings' => $this->getSettings() + InlineEntityFormComplex::defaultSettings(),
        'third_party_settings' => []
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings()
  {
    $defaults = parent::defaultSettings() + ['allow_edit' => FALSE, 'element_wrapper' => 'container'];
    $defaults += InlineEntityFormComplex::defaultSettings();

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state)
  {
    $element = parent::settingsForm($form, $form_state);
    $element['allow_edit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow users to edit existing item'),
      '#default_value' => $this->getSetting('allow_edit'),
    ];
    $element['element_wrapper'] = [
      '#type' => 'select',
      '#title' => $this->t('Wrapper element'),
      '#options' => [
        'details' => t('Details'),
        'fieldset' => t('Fieldset'),
        'container' => t('Container')
      ],
      '#default_value' => $this->getSetting('element_wrapper'),
    ];
    $element += $this->iefcWidget->settingsForm($form, $form_state);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary()
  {
    return $this->iefcWidget->settingsSummary();
  }

  protected function reflectionMethod($method)
  {
    $args = func_get_args();
    array_shift($args);
    try {
      $reflectionMethod = new \ReflectionMethod('\Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex', $method);
      $reflectionMethod->setAccessible(TRUE);
      return call_user_func_array([$reflectionMethod, 'invoke'], array_merge([$this->iefcWidget], $args));
    } catch (\ReflectionException $e) {
    }
    return FALSE;
  }

  /**
   * Sets inline entity form ID.
   *
   * @param string $ief_id
   *   The inline entity form ID.
   * @see ::makeIefId
   *
   */
  protected function setIefId($ief_id)
  {
    $this->iefId = $ief_id;
  }

  /**
   * Gets inline entity form ID.
   *
   * @return string
   *   Inline entity form ID.
   */
  protected function getIefId()
  {
    return $this->iefId;
  }

  /**
   * @inheritDoc
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state)
  {
    $element += parent::formElement($items, $delta, $element, $form, $form_state);
    $element['target_id'] = [
      '#type' => 'select',
      '#options' => $element['#options'],
      '#default_value' => $element['#default_value'],
      '#multiple' => $element['#multiple']
    ];

    $field_parents = !empty($element['#field_parents']) ? $element['#field_parents'] : [];

    $parents = array_merge($field_parents, [$items->getName(), 'form']);

    $this->setIefId(Crypt::hashBase64(implode('-', $parents)));
    $this->reflectionMethod('setIefId', $this->getIefId());
    $wrapper = 'inline-entity-form-' . $this->getIefId();

    $element = [
      '#type' => $this->getSetting('collapsible') ? 'details' : $this->getSetting('element_wrapper'),
      '#tree' => TRUE,
      '#description' => $this->fieldDefinition->getDescription(),
      '#prefix' => '<div id="' . $wrapper . '">',
      '#suffix' => '</div>',
      '#ief_id' => $this->getIefId(),
      '#ief_root' => TRUE,
      '#translating' => $this->reflectionMethod('isTranslating', $form_state),
      '#field_title' => $this->fieldDefinition->getLabel(),
      '#after_build' => [
        ['\Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex', 'removeTranslatabilityClue'],
      ],
    ] + $element;

    $widget_state = $form_state->get(['inline_entity_form', $element['#ief_id']]);
    if (empty($widget_state)) {
      $widget_state = [
        'instance' => $this->fieldDefinition,
        'form' => NULL,
        'delete' => [],
        'entities' => [],
      ];
      $form_state->set(['inline_entity_form', $element['#ief_id']], $widget_state);
    }

    if (!empty($element['actions']['ief_add'])) {
      $element['actions']['ief_add']['#value'] = t('Add');
    }

    if ($element['#type'] == 'details') {
      $element['#open'] = !$this->getSetting('collapsed');
    }

    $value_path = array_merge($element['#field_parents'], [$this->fieldDefinition->getName(), $this->column]);

    $values = $form_state->get($value_path);
    if (!empty($values)) {
      $element['target_id']['#value'] = $values;
    }

    $settings = $this->getSettings();
    $create_bundles = $this->reflectionMethod('getCreateBundles');
    $allow_new = $settings['allow_new'] && count($create_bundles);
    $allow_edit = $settings['allow_edit'] && count($this->options);
    $parent_langcode = $items->getEntity()->language()->getId();
    $new_key = 0;

    // If no form is open, show buttons that open one.
    $open_form = $form_state->get(['inline_entity_form', $element['#ief_id'], 'form']);

    $target_id = NestedArray::getValue($form_state->getUserInput(), $value_path);

    if (empty($open_form) || ($open_form == 'edit' && ($target_id == '_none'))) {
      if ($allow_new) {
        $element['actions']['ief_add'] = [
          '#type' => 'submit',
          '#value' => $this->t('Add'),
          '#name' => 'ief-' . $element['#ief_id'] . '-add',
          '#ief_id' => $element['#ief_id'],
          '#ajax' => [
            'callback' => 'inline_entity_form_get_element',
            'wrapper' => 'inline-entity-form-' . $element['#ief_id'],
          ],
          '#submit' => ['inline_entity_form_open_form'],
          '#ief_form' => 'add',
        ];
      }
      if ($allow_edit) {
        $element['actions']['ief_entity_edit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Edit'),
          '#name' => 'ief-' . $element['#ief_id'] . '-entity-edit-' . 0,
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => 'inline_entity_form_get_element',
            'wrapper' => 'inline-entity-form-' . $element['#ief_id'],
          ],
          '#submit' => ['inline_entity_form_open_form'],
          '#ief_row_delta' => 0,
          '#ief_row_form' => 'edit',
          '#ief_form' => 'edit',
        ];
      }
    }

    if ($open_form == 'add') {
      $element['target_id']['#type'] = 'value';
      $element['form'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ief-form', 'ief-form-row']],
        'inline_entity_form' => $this->reflectionMethod(
          'getInlineEntityForm',
          'add',
          $this->reflectionMethod('determineBundle', $form_state),
          $parent_langcode,
          $new_key,
          array_merge($parents, [$new_key])
        )
      ];
      $element['form']['inline_entity_form']['#process'] = [
        ['\Drupal\inline_entity_form\Element\InlineEntityForm', 'processEntityForm'],
        ['\Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormBase', 'addIefSubmitCallbacks'],
        ['\Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex', 'buildEntityFormActions'],
      ];
    } elseif ($open_form == 'edit') {
      $element['target_id']['#type'] = 'value';
      // There's a form open, show it.
      $target_type = $this->getFieldSetting('target_type');

      if (!empty($target_id)) {
        if (is_array($target_id)) {
          $target_id = reset($target_id);
        }
        $entity = $this->entityTypeManager->getStorage($target_type)->load($target_id);
      }
      if ($entity) {
        $entity_id = $entity->id();
        if (empty($entity_id) || $entity->access('update')) {
          $form_state->set(['inline_entity_form', $element['#ief_id'], 'entities', $new_key, 'entity'], $entity);
          // Build a parents array for this element's values in the form.
          $element['entities'][$new_key]['form'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ief-form', 'ief-form-row']],
            'inline_entity_form' =>
              $this->reflectionMethod(
                'getInlineEntityForm',
                'edit',
                $entity->bundle(),
                $entity->language()->getId(),
                $new_key,
                array_merge($parents, [$new_key]),
                $entity
              )
          ];

          $element['entities'][$new_key]['form']['inline_entity_form']['#process'] = [
            ['\Drupal\inline_entity_form\Element\InlineEntityForm', 'processEntityForm'],
            ['\Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormBase', 'addIefSubmitCallbacks'],
            ['\Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex', 'buildEntityFormActions']
          ];
        }
      } else {
        $form_state->set(['inline_entity_form', $element['#ief_id'], 'form'], NULL);
      }
    }
    return $element;
  }

  /**
   * Form validation handler for widget elements.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateElement(array $element, FormStateInterface $form_state)
  {
    if ($element['#required'] && $element['target_id']['#value'] == '_none') {
      $form_state->setError($element, t('@name field is required.', ['@name' => $element['#title']]));
    }
    if (is_array($element['target_id']['#value'])) {
      $values = array_values($element['target_id']['#value']);
    } else {
      $values = [$element['target_id']['#value']];
    }

    // Filter out the 'none' option. Use a strict comparison, because
    // 0 == 'any string'.
    $index = array_search('_none', $values, TRUE);
    if ($index !== FALSE) {
      unset($values[$index]);
    }

    $triggering_element = $form_state->getTriggeringElement();
    if (!empty($triggering_element['#ief_submit_trigger'])) {
      $ief_id = $element['#ief_id'];
      $widget_state = &$form_state->get(['inline_entity_form', $ief_id]);
      /** @var FieldConfigInterface $instance */
      $instance = $widget_state['instance'];
      $entities_count = ($element['#multiple']) ? count($values) : (!empty($values) ? 1 : 0);
      $cardinality = $instance->getFieldStorageDefinition()->getCardinality();
      $cardinality_reached = ($cardinality > 0 && $entities_count == $cardinality);
      // If the inline entity form is still open, then its entity hasn't
      // been transferred to the IEF form state yet.
      if (!empty($widget_state['form']) && ($widget_state['form'] == 'add' || $widget_state['form'] == 'edit')) {
        if ($widget_state['form'] == 'edit') {
          $parents = ['entities', 0, 'form', 'inline_entity_form'];
        } else {
          $parents = ['form', 'inline_entity_form'];
        }
        $ief = NestedArray::getValue($element, $parents);
        $entity = $ief['#entity'];
        try {
          $entity->save();
          $widget_state['entities'] = [];
          if ($element['#multiple']) {
            if (!$cardinality_reached) {
              $values[] = $entity->id();
            }
          } else {
            $values = [$entity->id()];
          }
        } catch (Exception $ex) {
          //throw $ex;
        }
      }
    }

    // Transpose selections from field => delta to delta => field.
    $items = [];
    foreach ($values as $value) {
      $items[] = [$element['#key_column'] => $value];
    }

    $form_state->setValueForElement($element, $items);
    $form_state->set(array_merge($element['#parents'], ['target_id']), $values);
  }
}
