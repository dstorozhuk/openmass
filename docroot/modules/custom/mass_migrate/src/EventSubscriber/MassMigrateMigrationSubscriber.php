<?php

namespace Drupal\mass_migrate\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\node\Entity\Node;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class PreMigrationSubscriber.
 *
 * Run a test to validate that the server is available.
 *
 * @package Drupal\mass_migrate
 */
class MassMigrateMigrationSubscriber implements EventSubscriberInterface {

  /**
   * Get subscribed events.
   *
   * @inheritdoc
   */
  public static function getSubscribedEvents() {
    $events[MigrateEvents::POST_ROW_SAVE][] = ['onMigratePostRowSave'];
    return $events;
  }

  /**
   * Check for our specified last node migration and run our flagging mechanisms.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The import event object.
   */
  public function onMigratePostRowSave(MigratePostRowSaveEvent $event) {
    $_ENV['MASS_FLAGGING_BYPASS'] = TRUE;
    $destination_id_values = $event->getDestinationIdValues();
    $source_id_values = $event->getRow()->getSourceIdValues();
    $query = \Drupal::entityQuery('node')
      ->condition('field_primary_parent', $source_id_values['nid'])
      ->accessCheck(FALSE);
    $results = $query->execute();
    $nodes = Node::loadMultiple($results);
    if (!empty($nodes)) {
      foreach ($nodes as $node) {
        $node->set('field_primary_parent', $destination_id_values[0]);
        $node->save();
      }
    }
  }

}
