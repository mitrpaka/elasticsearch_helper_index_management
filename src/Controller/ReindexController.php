<?php

namespace Drupal\elasticsearch_helper_index_management\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\elasticsearch_helper\Plugin\ElasticsearchIndexManager;
use Drupal\elasticsearch_helper_index_management\ElasticsearchQueueManagerInterface;

/**
 * Class ReindexController.
 */
class ReindexController extends ControllerBase {
  /**
   * Drupal\elasticsearch_helper\Plugin\ElasticsearchIndexManager definition.
   *
   * @var \Drupal\elasticsearch_helper\Plugin\ElasticsearchIndexManager
   */
  protected $elasticsearchHelperPluginManager;

  /**
   * Drupal\elasticsearch_helper_index_management\ElasticsearchQueueManager definition.
   *
   * @var \Drupal\elasticsearch_helper_index_management\ElasticsearchQueueManager
   */
  protected $elasticsearchQueueManager;

  /**
   * Constructs a new IndexListController object.
   */
  public function __construct(ElasticsearchIndexManager $elasticsearch_plugin_manager, ElasticsearchQueueManagerInterface $elasticsearch_queue_manager) {
    $this->elasticsearchHelperPluginManager = $elasticsearch_plugin_manager;
    $this->elasticsearchQueueManager = $elasticsearch_queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.elasticsearch_index.processor'),
      $container->get('elasticsearch_helper_index_management.queue_manager')
    );
  }

  /**
   * Display current re-index status.
   *
   * @return string
   *   Status markup.
   */
  public function status($index_id) {
    $definition = $this->elasticsearchHelperPluginManager->getDefinition($index_id);

    $status = $this->elasticsearchQueueManager->getStatus($definition['id']);

    if ($status['total']) {
      $status_text = $status['processed'] . '/' . $status['total'] . ' items processed';

      if ($status['errors']) {
        $status_text .= ' (' . $status['errors'] . ' items not indexed due to errors)';
      }
    }
    else {
      $status_text = 'There are currently no items queued for re-indexing';
    }

    $rows = [
      ['Index', $definition['label'] . ' (' . $definition['id'] . ')'],
      ['Entity Type', $definition['entityType']],
      ['Status', $status_text],
    ];

    return [
      '#type' => 'table',
      '#rows' => $rows,
    ];
  }

  /**
   * Add items to re-index queue.
   */
  public function queueAll($index_id) {
    $this->elasticsearchQueueManager->addAll($index_id);

    return $this->redirect('elasticsearch_helper_index_management.reindex_controller_status', ['index_id' => $index_id]);
  }

  /**
   * Process queue items.
   */
  public function processAll($index_id) {
    // Get items from processing.
    $items = $this->elasticsearchQueueManager->getItems($index_id);

    // Create a batch for processing re-indexing.
    $batch = [
      'title' => $this->t('Re-indexing @id', ['@id' => $index_id]),
      'operations' => [],
      'init_message' => $this->t('Starting'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('An error occurred during processing'),
      'finished' => '\Drupal\elasticsearch_helper_index_management\ElasticsearchBatchManager::processFinished',
    ];

    foreach ($items as $item) {
      if (!$item->status) {
        $batch['operations'][] = ['\Drupal\elasticsearch_helper_index_management\ElasticsearchBatchManager::processOne', [$item->id]];
      }
    }

    batch_set($batch);

    return batch_process(Url::fromRoute('elasticsearch_helper_index_management.reindex_controller_status', ['index_id' => $index_id]));
  }

  /**
   * Delete all items from re-index queue.
   */
  public function clear($index_id) {
    $this->elasticsearchQueueManager->clear($index_id);

    return $this->redirect('elasticsearch_helper_index_management.reindex_controller_status', ['index_id' => $index_id]);
  }

}