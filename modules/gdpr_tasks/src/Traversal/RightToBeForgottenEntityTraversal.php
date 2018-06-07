<?php

namespace Drupal\gdpr_tasks\Traversal;

use Drupal\anonymizer\Anonymizer\AnonymizerFactory;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\TypedData\Exception\ReadOnlyException;
use Drupal\gdpr_fields\Entity\GdprField;
use Drupal\gdpr_fields\Entity\GdprFieldConfigEntity;
use Drupal\gdpr_fields\EntityTraversal;
use Drupal\gdpr_fields\GDPRCollector;

/**
 * Entity traversal used for Right to be Forgotten requests.
 *
 * @package Drupal\gdpr_tasks\Traversal
 */
class RightToBeForgottenEntityTraversal extends EntityTraversal {

  /**
   * Factory used to retrieve anonymizer to use on a particular field.
   *
   * @var \Drupal\anonymizer\Anonymizer\AnonymizerFactory
   */
  private $anonymizerFactory;

  /**
   * Drupal module handler for hooks.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, ModuleHandlerInterface $module_handler, AnonymizerFactory $anonymizer_factory) {
    parent::__construct($entityTypeManager, $entityFieldManager);
    $this->moduleHandler = $module_handler;
    $this->anonymizerFactory = $anonymizer_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function traverse(EntityInterface $entity) {
    $results = [
      'errors' => [],
      'successes' => [],
      'failures' => [],
      'log' => [],
    ];

    $progress = [];
    $this->doTraversalRecursive($entity, $progress, NULL, $results);
    return $results;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function processEntity(FieldableEntityInterface $entity, GdprFieldConfigEntity $config, $row_id, array &$results) {
    $entity_success = TRUE;
    $entity_type = $entity->getEntityTypeId();

    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $entity->bundle());
    $field_configs = $config->getFieldsForBundle($entity->bundle());

    // Re-load a fresh copy of the entity from storage so we don't
    // end up modifying any other references to the entity in memory.
    /* @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->entityTypeManager->getStorage($entity_type)
      ->loadUnchanged($entity->id());

    foreach ($fields as $field_id => $field_definition) {
      $field_config = isset($field_configs[$field_id]) ? $field_configs[$field_id] : NULL;

      // If the field is not configured, not enabled,
      // or not enabled for RTF, then skip it.
      if ($field_config === NULL
        || !$field_config->enabled
        || !in_array($field_config->rtf, ['anonymize', 'remove', 'maybe'])) {
        continue;
      }

      $mode = $field_config->rtf;
      $field = $entity->get($field_id);

      $success = TRUE;
      $msg = NULL;
      $anonymizer = '';

      if ($mode == 'anonymize') {
        list($success, $msg, $anonymizer) = $this->anonymize($field, $field_definition, $field_config);
      }
      elseif ($mode == 'remove') {
        list($success, $msg) = $this->remove($field, $entity);
      }

      if ($success === TRUE) {
        $results['log'][] = [
          'entity_id' => $entity->id(),
          'entity_type' => $entity_type . '.' . $entity->bundle(),
          'field_name' => $field->getName(),
          'action' => $mode,
          'anonymizer' => $anonymizer,
        ];
      }
      else {
        // Could not anonymize/remove field. Record to errors list.
        // Prevent entity from being saved.
        $entity_success = FALSE;
        $results['errors'][] = $msg;
      }
    }

    if ($entity_success) {
      $results['successes'][] = $entity;
    }
    else {
      $results['failures'][] = $entity;
    }
  }

  /**
   * Removes the field value.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The current field to process.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to remove.
   *
   * @return array
   *   First element is success boolean, second element is the error message.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function remove(FieldItemListInterface $field, EntityInterface $entity) {
    try {
      // If this is the entity's ID, treat the removal as remove the entire
      // entity.
      $entity_type = $entity->getEntityType();
      if ($entity_type->getKey('id') == $field->getName()) {
        $entity->delete();
      }
      // Check if the property can be removed.
      elseif (!GDPRCollector::propertyCanBeRemoved($entity_type, $field->getFieldDefinition(), $error_message)) {
        return [FALSE, $error_message];
      }

      // Otherwise assume we can simply clear the field.
      $field->setValue(NULL);
      return [TRUE, NULL];
    }
    catch (ReadOnlyException $e) {
      return [FALSE, $e->getMessage()];
    }
  }

  /**
   * Runs anonymize functionality against a field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to anonymize.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param \Drupal\gdpr_fields\Entity\GdprField $field_config
   *   GDPR field configuration.
   *
   * @return array
   *   First element is success boolean, second element is the error message.
   */
  private function anonymize(FieldItemListInterface $field, FieldDefinitionInterface $field_definition, GdprField $field_config) {
    $anonymizer_id = $this->getAnonymizerId($field_definition, $field_config);

    if (!$anonymizer_id) {
      return [
        FALSE,
        "Could not anonymize field {$field->getName()}. Please consider changing this field from 'anonymize' to 'remove', or register a custom anonymizer.",
        NULL,
      ];
    }

    try {
      $anonymizer = $this->anonymizerFactory->get($anonymizer_id);
      $field->setValue($anonymizer->anonymize($field->value, $field));
      return [TRUE, NULL, $anonymizer_id];
    }
    catch (\Exception $e) {
      return [FALSE, $e->getMessage(), NULL];
    }
  }

  /**
   * Gets the ID of the anonymizer plugin to use on this field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param \Drupal\gdpr_fields\Entity\GdprField $field_config
   *   GDPR field configuration.
   *
   * @return string
   *   The anonymizer ID or null.
   */
  private function getAnonymizerId(FieldDefinitionInterface $field_definition, GdprField $field_config) {
    $anonymizer = $field_config->anonymizer;
    $type = $field_definition->getType();

    if (!$anonymizer) {
      // No anonymizer defined directly on the field.
      // Instead try and get one for the datatype.
      $anonymizers = [
        'string' => 'gdpr_text_anonymizer',
        'datetime' => 'gdpr_date_anonymizer',
      ];

      $this->moduleHandler->alter('gdpr_type_anonymizers', $anonymizers);
      $anonymizer = $anonymizers[$type];
    }
    return $anonymizer;
  }

}
