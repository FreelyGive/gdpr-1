<?php

namespace Drupal\gdpr_fields;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\gdpr_fields\Entity\GdprField;
use Drupal\gdpr_fields\Entity\GdprFieldConfigEntity;

/**
 * Base class for traversing entities.
 *
 * @package Drupal\gdpr_fields
 */
abstract class EntityTraversal {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity storage for GDPR config entities.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $configStorage;

  /**
   * Reverse relationship information.
   *
   * @var \Drupal\gdpr_fields\Entity\GdprField[]
   */
  private $reverseRelationshipFields = NULL;

  /**
   * EntityTraversal constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->configStorage = $this->entityTypeManager->getStorage('gdpr_fields_config');
  }

  /**
   * Traverses the entity relationship tree.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to traverse.
   *
   * @return array
   *   Results collected by the traversal.
   *   By default this will be a nested array. The first dimension is
   *   keyed by entity type and contains an array keyed by  entity ID.
   *   The values will be the entity instances (although this can be changed by
   *   overriding the handleEntity method).
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function traverse(EntityInterface $entity) {
    $progress = [];
    $results = [];
    $this->doTraversalRecursive($entity, $progress, $results);
    return $results;
  }

  /**
   * Traverses the entity relationship tree.
   *
   * Calls the handleEntity method for every entity found.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The root entity to traverse.
   * @param array $progress
   *   Tracks which entities have been handled.
   * @param array $results
   *   Tracks resulting metadata about processed entity fields.
   * @param \Drupal\gdpr_fields\Entity\GdprField|null $parent_config
   *   (Optional) The parent config field settings.
   * @param int|null $row_id
   *   (Optional) The row to place the information in.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function doTraversalRecursive(EntityInterface $entity, array &$progress, array& $results, GdprField $parent_config = NULL, $row_id = NULL) {
    // If the entity is not fieldable, don't continue.
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }

    $entity_type = $entity->getEntityTypeId();

    if ($entity_type == 'gdpr_task') {
      // Explicitly make sure we don't traverse any links to gdpr_task
      // even if the user has explicitly included the reference for traversal.
      return;
    }

    // Check for infinite loop.
    if (isset($progress[$entity_type][$entity->id()])) {
      return;
    }

    if (!isset($row_id)) {
      $row_id = $entity->id();
    }

    // Store the entity in progress to make sure we don't get stuck
    // in an infinite loop by processing the same entity again.
    $progress[$entity_type][$entity->id()] = $entity;

    // GDPR config for this entity.
    /* @var \Drupal\gdpr_fields\Entity\GdprFieldConfigEntity $config */
    $config = $this->configStorage->load($entity_type);

    // Let subclasses do with the entity. They will add to the $results array.
    $this->processEntity($entity, $config, $row_id, $results, $parent_config);

    // Find relationships from this entity.
    $fields = $config->getFieldsForBundle($entity->bundle());

    foreach ($fields as $field_config) {
      // Only include fields explicitly enabled for entity traversal.
      if ($field_config->includeRelatedEntities() && $entity->hasField($field_config->name)) {
        // If there is no value, we don't need to proceed.
        $referenced_entities = $entity->get($field_config->name)->referencedEntities();

        if (empty($referenced_entities)) {
          continue;
        }

        $single_cardinality = $entity->get($field_config->name)->getFieldDefinition()
          ->getFieldStorageDefinition()->getCardinality() == 1;

        $passed_row_id = $single_cardinality ? $row_id : NULL;
        // Loop through each child entity and traverse their relationships too.
        foreach ($referenced_entities as $child_entity) {
          $this->doTraversalRecursive($child_entity, $progress, $results, $field_config, $passed_row_id);
        }
      }
    }

    // Now we want to look up any reverse relationships that have been marked
    // as owner.
    foreach ($this->getAllReverseRelationships() as $relationship) {
      if ($relationship['target_type'] == $entity_type) {
        // Load all instances of this entity where the field value is the same
        // as our entity's ID.
        $storage = $this->entityTypeManager->getStorage($relationship['entity_type']);

        $ids = $storage->getQuery()
          ->condition($relationship['field'], $entity->id())
          ->execute();

        foreach ($storage->loadMultiple($ids) as $related_entity) {
          $this->doTraversalRecursive($related_entity, $progress, $results, $relationship['config']);
        }
      }
    }
  }

  /**
   * Handles the entity.
   *
   * By default this just returns the entity instance, but derived classes
   * should override this method if they need to collect additional data on the
   * instance.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to handle.
   * @param \Drupal\gdpr_fields\Entity\GdprFieldConfigEntity $config
   *   GDPR config for this entity.
   * @param string $row_id
   *   Row identifier used in SARs.
   * @param array $results
   *   Subclasses should add any data they need to collect to the results array.
   * @param \Drupal\gdpr_fields\Entity\GdprField|null $parent_config
   *   Parent's config.
   */
  abstract protected function processEntity(FieldableEntityInterface $entity, GdprFieldConfigEntity $config, $row_id, array &$results, GdprField $parent_config = NULL);

  /**
   * Gets all reverse relationships configured in the system.
   *
   * @return array
   *   Information about reversible relationships.
   */
  protected function getAllReverseRelationships() {
    if ($this->reverseRelationshipFields !== NULL) {
      // Make sure reverse relationships are cached.
      // as this is called many times in the recursion loop.
      return $this->reverseRelationshipFields;
    }

    $this->reverseRelationshipFields = [];
    /* @var \Drupal\gdpr_fields\Entity\GdprFieldConfigEntity $config  */
    foreach ($this->configStorage->loadMultiple() as $config) {
      foreach ($config->getAllFields() as $field) {
        if ($field->enabled && $field->isOwner()) {
          foreach ($this->entityFieldManager->getFieldDefinitions($config->id(), $field->bundle) as $field_definition) {
            if ($field_definition->getName() == $field->name && $field_definition->getType() == 'entity_reference') {
              $this->reverseRelationshipFields[] = [
                'entity_type' => $config->id(),
                'bundle' => $field->bundle,
                'field' => $field->name,
                'config' => $field,
                'target_type' => $field_definition->getSetting('target_type'),
              ];
            }
          }
        }
      }
    }

    return $this->reverseRelationshipFields;
  }

  /**
   * Gets the entity bundle label. Useful for display traversal.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to get the bundle label for.
   *
   * @return string
   *   Bundle label
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  protected function getBundleLabel(EntityInterface $entity) {
    $entity_definition = $entity->getEntityType();
    $bundle_type = $entity_definition->getBundleEntityType();

    if ($bundle_type) {
      $bundle_storage = $this->entityTypeManager->getStorage($bundle_type);
      $bundle_entity = $bundle_storage->load($entity->bundle());
      $bundle_label = $bundle_entity == NULL ? '' : $bundle_entity->label();
    }
    else {
      $bundle_label = $entity_definition->getLabel();
    }
    return $bundle_label;
  }

}
