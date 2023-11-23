<?php

namespace Drupal\mass_metatag\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\node\Entity\Node;

/**
 * Class MassMetatagUtilities.
 *
 * @package Drupal\mass_metatag\Service
 */
class MassMetatagUtilities {

  /**
   * Slugifies a string by making it lowercase and separating words with hyphens.
   *
   * @param string $string
   *   The string to slugify.
   *
   * @return string
   *   A slugified version of the string.
   */
  public function slugify($string) {
    // Replace one or more consecutive whitespace characters with a hyphen.
    $without_whitespace = preg_replace('/[\s_]+/', '-', $string);

    // Lowercase and remove characters which aren't alphanumeric or hyphens.
    return preg_replace('/[^a-z\d-]/', '', strtolower($without_whitespace));
  }

  /**
   * Gets all Organization names for the passed node, including all parent Orgs.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to get Orgs and parent Orgs from.
   * @param bool $parent_only
   *   If the flag is specified only parents slugified titles will be returned.
   * @param bool $parent_meta
   *   If the flag is specified only parents metadata will be returned.
   * @param bool $contextual_search
   *   If the flag is specified the returned data will be used
   *   in contextual search.
   *
   * @return string[]
   *   The array of slugified Org names related to this node.
   */
  public function getAllOrgsFromNode(Node $node, bool $parent_only = FALSE, bool $parent_meta = FALSE, bool $contextual_search = FALSE) {
    $result = [];

    // The array that will hold all the orgs to check for parents.
    $orgs = [];
    // Add the current node for checking.
    $orgs[] = $node;
    $checked_orgs = [];
    // While there are organizations in the array, check for parent orgs.
    while (!empty($orgs)) {
      // Pop an org node from the array.
      $node = array_shift($orgs);
      // If the current node is an org page, add the title to the values
      // and if there is a parent org, add it to the array for checking.
      if ($node->bundle() === 'org_page') {
        // If it is an unchecked org, add the slugified title to values.
        if (!$parent_only) {
          if (!in_array($node->id(), $checked_orgs)) {
            if (!$contextual_search) {
              $result[] = $this->slugify(trim($node->label()));
            }
            else {
              if ($node->hasField('field_org_no_search_filter')) {
                if ($node->field_org_no_search_filter->value != '1') {
                  $result[] = trim($node->label());
                }
              }
            }
          }
        }
        else {
          // If there is a parent org, add it to the array to check.
          if (!$node->field_parent->isEmpty() && !is_null($node->field_parent->entity) && !in_array($node->field_parent->entity->id(), $checked_orgs)) {
            $orgs[] = $node->field_parent->entity;
            if ($parent_meta) {
              $result[$node->field_parent->entity->id()] = [
                'title' => $node->field_parent->entity->getTitle(),
                'uuid' => $node->field_parent->entity->uuid(),
              ];
            }
            else {
              if (!$contextual_search) {
                $result[] = $this->slugify(trim($node->field_parent->entity->label()));
              }
              else {
                $parent = $node->field_parent->entity;
                if ($parent) {
                  if ($parent->hasField('field_org_no_search_filter')) {
                    if ($parent->field_org_no_search_filter->value != '1') {
                      if ($node->hasField('field_include_parent_org_search')) {
                        if ($node->field_include_parent_org_search == 1) {
                          $result[] = trim($parent->label());
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
      // For all other nodes, get all the organizations referenced
      // and add it to the orgs array so they can be checked for parents.
      if ($node->hasField('field_organizations')) {
        /** @var \Drupal\node\Entity\Node[] $org_pages */
        $org_pages = $node->field_organizations->referencedEntities();
        foreach ($org_pages as $org_page) {
          // Only add the referenced orgs if they have not already been
          // checked.
          if (!in_array($org_page->id(), $checked_orgs)) {
            $orgs[] = $org_page;
          }
        }
      }
      // Add the current org node to the checked array to keep track.
      $checked_orgs[] = $node->id();
    }

    return $result;
  }

  /**
   * Gets all Labels for the passed node, including all parent Orgs.
   *
   * @param ContentEntityInterface $entity
   *   The node to get Labels from.
   *
   * @return string[]
   *   The array of slugified Labels names related to this node.
   */
  public function getAllLabelsFromEntity(ContentEntityInterface $entity) {
    $result = [];

    if (!empty($entity)) {
      if ($entity->hasField('field_reusable_label')) {
        $labels = $entity->field_reusable_label->referencedEntities();
        foreach ($labels as $label) {
          $result[] = $this->slugify(trim($label->label()));
        }
      }
    }

    return $result;
  }

}
