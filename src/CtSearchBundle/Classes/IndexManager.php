<?php

namespace CtSearchBundle\Classes;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use CtSearchBundle\Classes\Mapping;
use CtSearchBundle\Datasource\Datasource;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\Container;

class IndexManager
{

  /**
   * @var Client
   */
  private $client;
  private $esUrl;

  /**
   * @var IndexManager
   */
  private static $instance;

  /**
   * IndexManager constructor.
   * @param string $esUrl
   */
  private function __construct($esUrl)
  {
    $this->esUrl = $esUrl;
  }

  /**
   * @return IndexManager
   */
  public static function getInstance()
  {
    if (IndexManager::$instance == null) {
      global $kernel;
      $esUrl = $kernel->getContainer()->getParameter('ct_search.es_url');
      IndexManager::$instance = new IndexManager($esUrl);
    }
    return IndexManager::$instance;
  }

  /**
   *
   * @return Client
   */
  function getClient()
  {
    if (isset($this->client)) {
      unset($this->client);
    }
    $clientBuilder = new ClientBuilder();
    $clientBuilder->setHosts(array($this->esUrl));
    $this->client = $clientBuilder->build();
    unset($clientBuilder);
    gc_enable();
    gc_collect_cycles();
    return $this->client;
  }

  function getServerInfo()
  {
    return $this->getClient()->info();
  }

  function getServerMajorVersionNumber(){
    $info = $this->getServerInfo();
    return (int)explode('.', $info['version']['number'])[0];
  }

  /**
   * @return User
   */
  private function getCurrentUser(){
    global $kernel;
    return $kernel->getContainer()->get('security.token_storage')->getToken()->getUser();
  }

  private function isCurrentUserAdmin(){
    global $kernel;
    return $kernel->getContainer()->get('security.authorization_checker')->isGranted('ROLE_ADMIN');
  }

  private function getCurrentUserAllowedIndexes(){
    /** @var User $user */
    $user = $this->getCurrentUser();
    $indexes = [];
    foreach($user->getGroups() as $groupId){
      $group = $this->getGroup($groupId);
      foreach($group->getIndexes() as $index){
        if(!in_array($index, $indexes)){
          $indexes[] = $index;
        }
      }
    }
    return $indexes;
  }

  private function getCurrentUserAllowedDatasources(){
    /** @var User $user */
    $user = $this->getCurrentUser();
    $datasources = [];
    foreach($user->getGroups() as $groupId){
      $group = $this->getGroup($groupId);
      foreach($group->getDatasources() as $ds){
        if(!in_array($ds, $datasources)){
          $datasources[] = $ds;
        }
      }
    }
    $createdByMe = $this->getDatasourcesByAuthor(null, $this->getCurrentUser()->getUsername());
    foreach($createdByMe as $item){
      if(!in_array($item->getId(), $datasources)){
        $datasources[] = $item->getId();
      }
    }
    return $datasources;
  }

  private function getCurrentUserAllowedMatchingLists(){
    /** @var User $user */
    $user = $this->getCurrentUser();
    $matchingLists = [];
    foreach($user->getGroups() as $groupId){
      $group = $this->getGroup($groupId);
      foreach($group->getMatchingLists() as $item){
        if(!in_array($item, $matchingLists)){
          $matchingLists[] = $item;
        }
      }
    }
    $createdByMe = $this->getMatchingListsByAuthor($this->getCurrentUser()->getUsername());
    foreach($createdByMe as $item){
      if(!in_array($item->getId(), $matchingLists)){
        $matchingLists[] = $item->getId();
      }
    }
    return $matchingLists;
  }

  private function getCurrentUserAllowedDictionaries(){
    /** @var User $user */
    $user = $this->getCurrentUser();
    $dictionaries = [];
    foreach($user->getGroups() as $groupId){
      $group = $this->getGroup($groupId);
      foreach($group->getDictionaries() as $item){
        if(!in_array($item, $dictionaries)){
          $dictionaries[] = $item;
        }
      }
    }
    return $dictionaries;
  }

  function getElasticInfo()
  {
    $info = array();
    $stats = $this->getClient()->indices()->stats();
    $allowed_indexes = $this->getCurrentUserAllowedIndexes();
    foreach ($stats['indices'] as $index_name => $stat) {
      if($this->isCurrentUserAdmin() || in_array($index_name, $allowed_indexes)) {
        $info[$index_name] = array(
          'count' => $stat['total']['docs']['count'] - $stat['total']['docs']['deleted'],
          'size' => round($stat['total']['store']['size_in_bytes'] / 1024 / 1024, 2) . ' MB',
        );
        $mappings = $this->getClient()->indices()->getMapping(array('index' => $index_name));
        foreach ($mappings[$index_name]['mappings'] as $mapping => $properties) {
          $info[$index_name]['mappings'][] = array(
            'name' => $mapping,
            'field_count' => count($properties['properties']),
          );
        }
        unset($mappings);
      }
    }
    unset($stats);
    return $info;
  }

  /**
   *
   * @param Index $index
   */
  function createIndex($index)
  {
    $settings = json_decode($index->getSettings(), true);
    if (isset($settings['creation_date']))
      unset($settings['creation_date']);
    if (isset($settings['version']))
      unset($settings['version']);
    if (isset($settings['uuid']))
      unset($settings['uuid']);
    $params = array(
      'index' => $index->getIndexName(),
    );
    $settings['analysis']['analyzer']['transliterator'] = array(
      'filter' => array('standard', 'asciifolding', 'lowercase'),
      'tokenizer' => 'keyword'
    );
    if (count($settings) > 0) {
      $params['body'] = array(
        'settings' => $settings,
      );
    }
    $this->getClient()->indices()->create($params);
  }

  /**
   *
   * @param Index $index
   */
  function updateIndex($index)
  {
    $settings = json_decode($index->getSettings(), true);
    if (isset($settings['creation_date']))
      unset($settings['creation_date']);
    if (isset($settings['version']))
      unset($settings['version']);
    if (isset($settings['uuid']))
      unset($settings['uuid']);
    if (isset($settings['number_of_shards']))
      unset($settings['number_of_shards']);
    if (isset($settings['analysis']))
      unset($settings['analysis']);
    if (count($settings) > 0) {
      $this->getClient()->indices()->putSettings(array(
        'index' => $index->getIndexName(),
        'body' => array(
          'settings' => $settings,
        ),
      ));
    }
  }

  /**
   *
   * @param Index $index
   */
  function getIndex($indexName)
  {
    try {
      $settings = $this->getClient()->indices()->getSettings(array(
        'index' => $indexName,
      ));
      $settings = $settings[$indexName]['settings']['index'];
      return new Index($indexName, json_encode($settings, JSON_PRETTY_PRINT));
    } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $ex) {
      return null;
    }
  }

  /**
   *
   * @param Index $index
   */
  function deleteIndex($index)
  {
    $this->getClient()->indices()->delete(array(
      'index' => $index->getIndexName(),
    ));
    $this->getClient()->indices()->flush();
  }

  /**
   *
   * @param string $indexName
   * @param string $mappingName
   * @return Mapping
   */
  function getMapping($indexName, $mappingName)
  {
    try {
      $mapping = $this->getClient()->indices()->getMapping(array(
        'index' => $indexName,
        'type' => $mappingName,
      ));
      if (isset($mapping[$indexName]['mappings'][$mappingName]['properties'])) {
        $obj = new Mapping($indexName, $mappingName, json_encode($mapping[$indexName]['mappings'][$mappingName]['properties'], JSON_PRETTY_PRINT));
        if (isset($mapping[$indexName]['mappings'][$mappingName]['dynamic_templates'])) {
          $obj->setDynamicTemplates(json_encode($mapping[$indexName]['mappings'][$mappingName]['dynamic_templates'], JSON_PRETTY_PRINT));
        }
        return $obj;
      } else
        return null;
    } catch (\Exception $ex) {
      return null;
    }
  }

  /**
   *
   * @param Mapping $mapping
   */
  function updateMapping($mapping)
  {
    if ($mapping->getWipeData()) {
      $this->getClient()->deleteByQuery(array(
        'index' => $mapping->getIndexName(),
        'type' => $mapping->getMappingName(),
        'body' => array(
          'query' => array(
            'match_all' => array()
          )
        )
      ));
    }
    $body = array(
      'properties' => json_decode($mapping->getMappingDefinition(), true),
    );
    if ($mapping->getDynamicTemplates() != NULL) {
      $body['dynamic_templates'] = json_decode($mapping->getDynamicTemplates(), true);
    }
    $this->getClient()->indices()->putMapping(array(
      'index' => $mapping->getIndexName(),
      'type' => $mapping->getMappingName(),
      'body' => $body,
    ));
  }

  /**
   *
   * @param string $indexName
   * @return string[]
   */
  function getAnalyzers($indexName)
  {
    $analyzers = array('standard', 'simple', 'whitespace', 'stop', 'keyword', 'pattern', 'language', 'snowball');
    $settings = $this->getClient()->indices()->getSettings(array(
      'index' => $indexName,
    ));
    if (isset($settings[$indexName]['settings']['index']['analysis']['analyzer'])) {
      foreach ($settings[$indexName]['settings']['index']['analysis']['analyzer'] as $analyzer => $definition) {
        $analyzers[] = $analyzer;
      }
    }
    unset($settings);
    return $analyzers;
  }

  /**
   *
   * @return string[]
   */
  function getFieldTypes()
  {
    $types = array('integer', 'long', 'float', 'double', 'boolean', 'date', 'ip', 'geo_point');
    if($this->getServerMajorVersionNumber() >= 5){
      $types = array_merge($types, array('text', 'keyword'));
    }
    else{
      $types = array_merge($types, array('string'));
    }
    asort($types);
    return $types;
  }

  /**
   *
   * @return string[]
   */
  function getDateFormats()
  {
    return array('basic_date', 'basic_date_time', 'basic_date_time_no_millis', 'basic_ordinal_date', 'basic_ordinal_date_time', 'basic_ordinal_date_time_no_millis', 'basic_time', 'basic_time_no_millis', 'basic_t_time', 'basic_t_time_no_millis', 'basic_week_date', 'basic_week_date_time', 'basic_week_date_time_no_millis', 'date', 'date_hour', 'date_hour_minute', 'date_hour_minute_second', 'date_hour_minute_second_fraction', 'date_hour_minute_second_millis', 'date_optional_time', 'date_time', 'date_time_no_millis', 'hour', 'hour_minute', 'hour_minute_second', 'hour_minute_second_fraction', 'hour_minute_second_millis', 'ordinal_date', 'ordinal_date_time', 'ordinal_date_time_no_millis', 'time', 'time_no_millis', 't_time', 't_time_no_millis', 'week_date', 'week_date_time', 'weekDateTimeNoMillis', 'week_year', 'weekyearWeek', 'weekyearWeekDay', 'year', 'year_month', 'year_month_day');
  }

  /**
   * @param Controller $controller
   * @return Datasource[]
   */
  function getDatasources($controller)
  {
    $allowed_datasources = $this->getCurrentUserAllowedDatasources();
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'datasource',
          'size' => 9999,
          'sort' => 'name:asc'
        ));
        $datasources = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            if (class_exists($hit['_source']['class']) && ($this->isCurrentUserAdmin() || in_array($hit['_id'], $allowed_datasources))) {
              /** @var Datasource $datasource */
              $datasource = new $hit['_source']['class']($hit['_source']['name'], $controller);
              $datasource->initFromSettings(unserialize($hit['_source']['definition']));
              $datasource->setId($hit['_id']);
              if(isset($hit['_source']['created_by'])){
                $datasource->setCreatedBy($hit['_source']['created_by']);
              }
              $datasources[$hit['_id']] = $datasource;
            }
          }
        }
        unset($r);
        usort($datasources, function (Datasource $d1, Datasource $d2) {
          return $d1->getName() >= $d2->getName();
        });
        return $datasources;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  /**
   * @param Controller $controller
   * @return Datasource[]
   */
  function getDatasourcesByAuthor($controller, $createdBy)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'datasource',
          'size' => 9999,
          'sort' => 'name:asc',
          'body' => array(
            'query' => array(
              'term' => array(
                'created_by' => $createdBy
              )
            )
          )
        ));
        $datasources = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            if (class_exists($hit['_source']['class'])) {
              /** @var Datasource $datasource */
              $datasource = new $hit['_source']['class']($hit['_source']['name'], $controller);
              $datasource->initFromSettings(unserialize($hit['_source']['definition']));
              $datasource->setId($hit['_id']);
              if(isset($hit['_source']['created_by'])){
                $datasource->setCreatedBy($hit['_source']['created_by']);
              }
              $datasources[$hit['_id']] = $datasource;
            }
          }
        }
        unset($r);
        usort($datasources, function (Datasource $d1, Datasource $d2) {
          return $d1->getName() >= $d2->getName();
        });
        return $datasources;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  /**
   *
   * @param string $id
   * @param \Symfony\Bundle\FrameworkBundle\Controller\Controller $controller
   * @return Datasource
   */
  function getDatasource($id, $controller)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'datasource',
          'body' => array(
            'query' => array(
              'match' => array(
                '_id' => $id,
              )
            )
          )
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          $hit = $r['hits']['hits'][0];
          /** @var Datasource $datasource */
          $datasource = new $hit['_source']['class']($hit['_source']['name'], $controller);
          $datasource->initFromSettings(unserialize($hit['_source']['definition']));
          $datasource->setId($id);
          $datasource->setHasBatchExecution(isset($hit['_source']['has_batch_execution']) && $hit['_source']['has_batch_execution']);
          if(isset($hit['_source']['created_by'])){
            $datasource->setCreatedBy($hit['_source']['created_by']);
          }
          unset($r);
          return $datasource;
        }
        return null;
      } catch (\Exception $ex) {
        return null;
      }
    } else {
      return null;
    }
  }

  function getDatasourceTypes(Container $container)
  {
    $serviceIds = $container->getParameter("ctsearch.datasources");
    $types = array();
    foreach ($serviceIds as $id) {
      $types[$container->get($id)->getDatasourceDisplayName()] = get_class($container->get($id));
    }
    return $types;
  }

  function getFilterTypes(Container $container)
  {
    $serviceIds = $container->getParameter("ctsearch.filters");
    $types = array();
    foreach ($serviceIds as $id) {
      $types[str_replace("\\", '\\\\', get_class($container->get($id)))] = $container->get($id)->getDisplayName();
    }
    return $types;
  }

  /**
   *
   * @param Datasource $datasource
   * @param string $id
   * @return type
   */
  public function saveDatasource($datasource, $id = null)
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', 'datasource') == null) {
      $mappingDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_datasource_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'datasource', $mappingDefinition));
    }
    if ($this->getMapping('.ctsearch', 'logs') == null) {
      $logsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_logs_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'logs', $logsDefinition));
    }
    $params = array(
      'index' => '.ctsearch',
      'type' => 'datasource',
      'body' => array(
        'class' => get_class($datasource),
        'definition' => serialize($datasource->getSettings()),
        'name' => $datasource->getName(),
        'has_batch_execution' => $datasource->isHasBatchExecution(),
        'created_by' => $this->getCurrentUser()->getUsername()
      )
    );
    if ($id != null) {
      $params['id'] = $id;
    }
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    return $r;
  }

  /**
   *
   * @param string $id
   * @return type
   */
  public function deleteDatasource($id)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'datasource',
        'id' => $id,
      )
    );
    $this->getClient()->indices()->flush();
  }

  /**
   *
   * @param Processor $processor
   * @param string $id
   * @return array
   */
  public function saveProcessor($processor, $id = null)
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', 'processor') == null) {
      $mappingDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_processor_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'processor', $mappingDefinition));
    }
    $datasource = $this->getDatasource($processor->getDatasourceId(), null);
    $params = array(
      'index' => '.ctsearch',
      'type' => 'processor',
      'body' => array(
        'datasource' => $processor->getDatasourceId(),
        'datasource_name' => $datasource->getName(),
        'datasource_siblings' => $processor->getTargetSiblings(),
        'target' => $processor->getTarget(),
        'definition' => serialize(json_decode($processor->getDefinition(), true))
      )
    );
    if ($id != null) {
      $params['id'] = $id;
    }
    $r = $this->getClient()->index($params);
    unset($datasource);
    unset($params);
    $this->getClient()->indices()->flush();
    return $r;
  }

  function getRawProcessors()
  {
    $allowed_datasources = $this->getCurrentUserAllowedDatasources();
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'processor',
          'size' => 9999,
          'sort' => 'datasource_name:asc,target:asc'
        ));
        $processors = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            if($this->isCurrentUserAdmin() || in_array($hit['_source']['datasource'], $allowed_datasources)) {
              $proc = array(
                'id' => $hit['_id'],
                'datasource_id' => $hit['_source']['datasource'],
                'datasource_name' => $hit['_source']['datasource_name'],
                'target' => $hit['_source']['target'],
                'definition' => json_encode(unserialize($hit['_source']['definition']), JSON_PRETTY_PRINT),
              );
              if (isset($hit['_source']['datasource_siblings'])) {
                $proc['datasource_siblings'] = $hit['_source']['datasource_siblings'];
              }
              $processors[] = $proc;
            }
          }
        }
        unset($r);
        usort($processors, function ($p1, $p2) {
          return $p1['datasource_name'] >= $p2['datasource_name'];
        });
        return $processors;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  function getRawProcessorsByDatasource($datasourceId)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'processor',
          'size' => 9999,
          'sort' => 'datasource_name:asc,target:asc',
          'body' => array(
            'query' => array(
              'bool' => array(
                'should' => array(
                  array(
                    'match' => array(
                      'datasource' => $datasourceId
                    )
                  ),
                  array(
                    'match' => array(
                      'datasource_siblings' => $datasourceId
                    )
                  )
                )
              )
            )
          )
        ));
        $processors = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            $proc = array(
              'id' => $hit['_id'],
              'datasource_id' => $hit['_source']['datasource'],
              'datasource_name' => $hit['_source']['datasource_name'],
              'target' => $hit['_source']['target'],
              'definition' => json_encode(unserialize($hit['_source']['definition']), JSON_PRETTY_PRINT),
            );
            if (isset($hit['_source']['datasource_siblings'])) {
              $proc['datasource_siblings'] = $hit['_source']['datasource_siblings'];
            }
            $processors[] = $proc;
          }
        }
        unset($r);
        return $processors;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  /**
   *
   * @param string $id
   * @return Processor
   */
  function getProcessor($id)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'processor',
          'body' => array(
            'query' => array(
              'match' => array(
                '_id' => $id,
              )
            )
          )
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          $hit = $r['hits']['hits'][0];
          $processor = new Processor($hit['_id'], $hit['_source']['datasource'], $hit['_source']['target'], json_encode(unserialize($hit['_source']['definition']), JSON_PRETTY_PRINT));
          if (isset($hit['_source']['datasource_siblings'])) {
            $processor->setTargetSiblings($hit['_source']['datasource_siblings']);
          }
          unset($r);
          return $processor;
        }
        return null;
      } catch (\Exception $ex) {
        return null;
      }
    } else {
      return null;
    }
  }

  /**
   *
   * @param string $id
   * @return type
   */
  public function deleteProcessor($id)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'processor',
        'id' => $id,
      )
    );
    $this->getClient()->indices()->flush();
  }

  /**
   *
   * @param string $indexName
   * @param string $mappingName
   * @param array $document
   * @return array
   */
  public function indexDocument($indexName, $mappingName, $document, $flush = true)
  {
    $id = null;
    if (isset($document['_id'])) {
      $id = $document['_id'];
      unset($document['_id']);
    }
    $params = array(
      'index' => $indexName,
      'type' => $mappingName,
      'body' => $document,
    );
    if ($id != null) {
      $params['id'] = $id;
    }
    $r = $this->getClient()->index($params);
    if ($flush) {
      $this->getClient()->indices()->flush();
    }
    unset($params);
    return $r;
  }

  public function flush()
  {
    $this->getClient()->indices()->flush();
  }

  /**
   *
   * @return \CtSearchBundle\Classes\SearchPage[]
   */
  function getSearchPages()
  {
    $allowed_indexes = $this->getCurrentUserAllowedIndexes();
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'search_page',
          'size' => 9999,
          'sort' => 'name:asc'
        ));
        $searchPages = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            $index_name = explode('.', $hit['_source']['mapping'])[0];
            if (isset($hit['_source']['mapping']) && ($this->isCurrentUserAdmin() || in_array($index_name, $allowed_indexes))) {//Check for CtSearch 2.2 compatibility
              $searchPages[] = new SearchPage($hit['_source']['name'], $hit['_source']['mapping'], unserialize($hit['_source']['definition']), $hit['_id']);
            }
          }
        }
        unset($r);
        return $searchPages;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  /**
   *
   * @return \CtSearchBundle\Classes\SearchAPI[]
   */
  function getSearchAPIs()
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'search_api',
          'size' => 9999,
          'sort' => 'index_name:asc'
        ));
        $searchAPIs = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            $searchAPIs[] = new SearchAPI($hit['_source']['mapping_name'], unserialize($hit['_source']['definition']), $hit['_id']);
          }
        }
        unset($r);
        return $searchAPIs;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  /**
   *
   * @param string $id
   * @return SearchPage
   */
  function getSearchPage($id)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'search_page',
          'body' => array(
            'query' => array(
              'match' => array(
                '_id' => $id,
              )
            )
          )
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          $hit = $r['hits']['hits'][0];
          return new SearchPage($hit['_source']['name'], $hit['_source']['mapping'], unserialize($hit['_source']['definition']), $hit['_id']);
        }
        return null;
      } catch (\Exception $ex) {
        return null;
      }
    } else {
      return null;
    }
  }

  /**
   *
   * @param SearchPage $searchPage
   * @return array
   */
  public function saveSearchPage($searchPage)
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', 'search_page') == null) {
      $mappingDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_search_page_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'search_page', $mappingDefinition));
    }
    $params = array(
      'index' => '.ctsearch',
      'type' => 'search_page',
      'body' => array(
        'name' => $searchPage->getName(),
        'mapping' => $searchPage->getMapping(),
        'definition' => serialize(json_decode($searchPage->getDefinition(), true))
      )
    );
    if ($searchPage->getId() != null) {
      $params['id'] = $searchPage->getId();
    }
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  /**
   *
   * @param string $id
   * @return type
   */
  public function deleteSearchPage($id)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'search_page',
        'id' => $id,
      )
    );
    $this->getClient()->indices()->flush();
  }

  public function search($indexName, $json, $from = 0, $size = 20, $type = null)
  {
    $body = json_decode($json, true);
    $this->sanitizeGlobalAgg($body);
    $params = array(
      'index' => $indexName,
      'body' => $body,
    );

    if ($type != null)
      $params['type'] = $type;
    $params['body']['from'] = $from;
    $params['body']['size'] = $size;
    try {
      return $this->getClient()->search($params);
    } catch (\Exception $ex) {
      return array();
    }
  }

  private function sanitizeGlobalAgg(&$array)
  { //Bug fix form empty queries in global aggregations
    if ($array != null) {
      foreach ($array as $k => $v) {
        if ($k == 'global' && empty($v))
          $array[$k] = new \stdClass();
        elseif (is_array($v))
          $this->sanitizeGlobalAgg($array[$k]);
      }
    }
  }

  public function analyze($indexName, $analyzer, $text)
  {
    return $this->getClient()->indices()->analyze(array(
      'index' => $indexName,
      'analyzer' => $analyzer,
      'text' => $text,
    ));
  }

  /**
   * @param string $type
   * @param string $message
   * @param mixed $object
   * @param Datasource $datasource
   */
  public function log($type, $message, $object, $datasource)
  {
    $this->indexDocument('.ctsearch', 'logs', array(
      'type' => $type,
      'message' => $message,
      'object' => json_encode($object),
      'date' => date('Y-m-d\TH:i:s'),
      'log_datasource_name' => $datasource->getName()
    ));
  }

  /**
   *
   * @return \CtSearchBundle\Classes\MatchingList[]
   */
  function getMatchingLists()
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $allowed_matching_lists = $this->getCurrentUserAllowedMatchingLists();
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'matching_list',
          'size' => 9999,
          'sort' => 'name:asc'
        ));
        $matchingLists = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            if($this->isCurrentUserAdmin() || in_array($hit['_id'], $allowed_matching_lists)) {
              $matchingList = new MatchingList($hit['_source']['name'], unserialize($hit['_source']['list']), $hit['_id']);
              if(isset($hit['_source']['created_by'])){
                $matchingList->setCreatedBy($hit['_source']['created_by']);
              }
              $matchingLists[] = $matchingList;
            }
          }
        }
        unset($r);
        return $matchingLists;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }
  /**
   *
   * @return \CtSearchBundle\Classes\MatchingList[]
   */
  function getMatchingListsByAuthor($createdBy)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'matching_list',
          'size' => 9999,
          'sort' => 'name:asc',
          'body' => array(
            'query' => array(
              'term' => array(
                'created_by' => $createdBy
              )
            )
          )
        ));
        $matchingLists = array();
        if (isset($r['hits']['hits'])) {
          foreach ($r['hits']['hits'] as $hit) {
            $matchingList = new MatchingList($hit['_source']['name'], unserialize($hit['_source']['list']), $hit['_id']);
            if(isset($hit['_source']['created_by'])){
              $matchingList->setCreatedBy($hit['_source']['created_by']);
            }
            $matchingLists[] = $matchingList;
          }
        }
        unset($r);
        return $matchingLists;
      } catch (\Exception $ex) {
        return array();
      }
    } else {
      return array();
    }
  }

  /**
   *
   * @param string $id
   * @return \CtSearchBundle\Classes\MatchingList
   */
  function getMatchingList($id)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'matching_list',
          'body' => array(
            'query' => array(
              'match' => array(
                '_id' => $id,
              )
            )
          )
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          $hit = $r['hits']['hits'][0];
          $matchingList = new MatchingList($hit['_source']['name'], unserialize($hit['_source']['list']), $hit['_id']);
          if(isset($hit['_source']['created_by'])){
            $matchingList->setCreatedBy($hit['_source']['created_by']);
          }
          return $matchingList;
        }
        return null;
      } catch (\Exception $ex) {
        return null;
      }
    } else {
      return null;
    }
  }

  /**
   *
   * @param MatchingList $matchingList
   * @return array
   */
  public function saveMatchingList($matchingList)
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', 'search_page') == null) {
      $mappingDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_matching_list_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'matching_list', $mappingDefinition));
    }
    $params = array(
      'index' => '.ctsearch',
      'type' => 'matching_list',
      'body' => array(
        'name' => $matchingList->getName(),
        'list' => serialize(json_decode($matchingList->getList(), true)),
        'created_by' => $this->getCurrentUser()->getUsername()
      )
    );
    if ($matchingList->getId() != null) {
      $params['id'] = $matchingList->getId();
    }
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  /**
   *
   * @param string $id
   * @return type
   */
  public function deleteMatchingList($id)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'matching_list',
        'id' => $id,
      )
    );
    $this->getClient()->indices()->flush();
  }

  public function getAvailableFilters($indexName)
  {
    $infos = $this->getClient()->indices()->getSettings(array(
      'index' => $indexName
    ));
    $filters = array();
    if (isset($infos[$indexName]['settings']['index']['analysis']['filter'])) {
      foreach ($infos[$indexName]['settings']['index']['analysis']['filter'] as $name => $filter) {
        if (!in_array($name, $filters)) {
          $filters[] = $name;
        }
      }
    }
    if (isset($infos[$indexName]['settings']['index']['analysis']['analyzer'])) {
      foreach ($infos[$indexName]['settings']['index']['analysis']['analyzer'] as $k => $analyzer) {
        if (isset($analyzer['filter'])) {
          foreach ($analyzer['filter'] as $filter) {
            if (!in_array($filter, $filters)) {
              $filters[] = $filter;
            }
          }
        }
      }
    }
    unset($infos);
    return $filters;
  }

  public function deleteByQuery($indexName, $mappingName, $query)
  {
    $this->getClient()->deleteByQuery(array(
      'index' => $indexName,
      'type' => $mappingName,
      'body' => $query
    ));
  }

  public function saveSavedQuery($target, $definition, $id = null)
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', 'saved_query') == null) {
      $savedQueryDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_saved_query_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'saved_query', $savedQueryDefinition));
    }
    $params = array(
      'index' => '.ctsearch',
      'type' => 'saved_query',
      'body' => array(
        'definition' => $definition,
        'target' => $target
      )
    );
    if ($id != null) {
      $params['id'] = $id;
    }
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  function getSavedQuery($id)
  {
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'saved_query',
          'body' => array(
            'query' => array(
              'match' => array(
                '_id' => $id,
              )
            )
          )
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          $hit = $r['hits']['hits'][0];
          return $hit['_source'];
        }
        return null;
      } catch (\Exception $ex) {
        return null;
      }
    } else {
      return null;
    }
  }

  function getSavedQueries()
  {
    $list = array();
    $allowed_indexes = $this->getCurrentUserAllowedIndexes();
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'saved_query',
          'size' => 9999
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          foreach ($r['hits']['hits'] as $hit) {
            $index_name = explode('.', $hit['_source']['target'])[0];
            if($this->isCurrentUserAdmin() || in_array($index_name, $allowed_indexes)) {
              $list[] = array(
                  'id' => $hit['_id']
                ) + $hit['_source'];
            }
          }
        }
        unset($r);
      } catch (\Exception $ex) {

      }
    }
    return $list;
  }

  public function deleteSavedQuery($id)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'saved_query',
        'id' => $id,
      )
    );
    $this->getClient()->indices()->flush();
  }

  public function getRecoPath($path_id, $host)
  {
    if ($this->getIndex('.ctsearch_reco') != null) {
      try {
        $query = array(
          'index' => '.ctsearch_reco',
          'type' => 'path',
          'body' => array(
            'query' => array(
              'bool' => array(
                'must' => array(
                  array(
                    'ids' => array(
                      'values' => array($path_id),
                    )
                  ),
                  array(
                    'term' => array(
                      'host' => $host
                    )
                  )
                )
              )
            )
          )
        );
        $r = $this->getClient()->search($query);
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          return array(
            'id' => $r['hits']['hits'][0]['_id']
          ) + $r['hits']['hits'][0]['_source'];
        }
      } catch (\Exception $ex) {

      }
    }
    return null;
  }

  public function getRecos($id, $host, $index, $mapping)
  {
    if ($this->getIndex('.ctsearch_reco') != null) {
      try {
        $query = array(
          'index' => '.ctsearch_reco',
          'type' => 'path',
          'body' => array(
            'size' => 0,
            'query' => array(
              'bool' => array(
                'must' => array(
                  array(
                    'term' => array(
                      'ids' => $id,
                    )
                  ),
                  array(
                    'term' => array(
                      'host' => $host
                    )
                  )
                )
              )
            ),
            'aggs' => array(
              'ids' => array(
                "terms" => array(
                  "field" => "ids",
                  "size" => 20,
                )
              )
            )
          )
        );
        $r = $this->getClient()->search($query);
        if (isset($r['aggregations']['ids']['buckets'])) {
          $ids = array();
          foreach ($r['aggregations']['ids']['buckets'] as $bucket) {
            if ($bucket['key'] != $id) {
              $ids[$bucket['key']] = array();
            }
          }
          if (count($ids) > 0) {
            $r = $this->getClient()->search(array(
              'index' => $index,
              'type' => $mapping,
              'body' => array(
                'size' => 20,
                'query' => array(
                  'ids' => array(
                    'values' => array_keys($ids)
                  )
                )
              )
            ));
            if (isset($r['hits']['hits'])) {
              foreach ($r['hits']['hits'] as $hit) {
                if (isset($ids[$hit['_id']])) {
                  $ids[$hit['_id']] = $hit['_source'];
                }
              }
            }
            foreach ($ids as $k => $data) {
              if (empty($data)) {
                unset($ids[$k]);
              }
            }
          }
          return $ids;
        }
      } catch (\Exception $ex) {

      }
    }
    return array();
  }

  public function saveRecoPath($path)
  {
    if ($this->getIndex('.ctsearch_reco') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_reco_index_settings.json');
      $this->createIndex(new Index('.ctsearch_reco', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch_reco', 'path') == null) {
      $savedQueryDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_reco_path_definition.json');
      $this->updateMapping(new Mapping('.ctsearch_reco', 'path', $savedQueryDefinition));
    }
    $params = array(
      'index' => '.ctsearch_reco',
      'type' => 'path',
      'id' => $path['id'],
      'body' => array(
        'host' => $path['host'],
        'ids' => $path['ids'],
      )
    );
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  public function saveStat($target, $facets = array(), $query = '', $analyzer = null, $apiUrl = '', $resultCount = 0, $responseTime = 0, $remoteAddress = '', $tag = '')
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch_reco', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', 'stat') == null) {
      $statDefinition = file_get_contents(__DIR__ . '/../Resources/' . ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_stat_definition.json');
      $this->updateMapping(new Mapping('.ctsearch', 'stat', $statDefinition));
    }
    $indexName = explode('.', $target)[0];
    $tokens = $analyzer != null && !empty($analyzer) && strlen($query) > 2 ? $this->analyze($indexName, $analyzer, $query) : array();
    if (isset($tokens['tokens'])) {
      $query_analyzed = array();
      foreach ($tokens['tokens'] as $token) {
        if (isset($token['token'])) {
          $query_analyzed[] = $token['token'];
        }
      }
      $query_analyzed = implode(' ', $query_analyzed);
    } else {
      $query_analyzed = '';
    }
    $params = array(
      'index' => '.ctsearch',
      'type' => 'stat',
      'body' => array(
        'stat_date' => date('Y-m-d\TH:i:s'),
        'stat_index' => $indexName,
        'stat_mapping' => $target,
        'stat_remote_addr' => $remoteAddress,
        'stat_log' => $tag,
        'stat_facets' => $facets,
        'stat_query' => array(
          'raw' => $query,
          'analyzed' => $query_analyzed
        ),
        'stat_api_url' => $apiUrl,
        'stat_result_count' => $resultCount,
        'stat_response_time' => $responseTime
      )
    );
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  public function getBackupRepositories()
  {
    $r = $this->getClient()->snapshot()->getRepository(array('repository' => '_all'));
    return $r;
  }

  public function createRepository($data)
  {
    $params = array(
      'repository' => preg_replace("/[^A-Za-z0-9]/", '_', strtolower($data['name'])),
      'body' => array(
        'type' => $data['type'],
        'settings' => array(
          'location' => $data['location'],
          'compress' => $data['compress'],
        )
      )
    );
    $this->getClient()->snapshot()->createRepository($params);
  }

  public function getRepository($name)
  {
    return $this->getClient()->snapshot()->getRepository(array('repository' => $name));
  }

  public function deleteRepository($name)
  {
    return $this->getClient()->snapshot()->deleteRepository(array('repository' => $name));
  }

  public function createSnapshot($repoName, $snapshotName, $indexes, $ignoreUnavailable = true, $includeGlobalState = false)
  {
    $this->getClient()->snapshot()->create(array(
      'repository' => $repoName,
      'snapshot' => preg_replace("/[^A-Za-z0-9]/", '_', strtolower($snapshotName)),
      'body' => array(
        'indices' => implode(',', $indexes),
        'ignore_unavailable' => $ignoreUnavailable,
        'include_global_state' => $includeGlobalState,
      )
    ));
  }

  public function getSnapshots($repoName)
  {
    return $this->getClient()->snapshot()->get(array('repository' => $repoName, 'snapshot' => '_all'));
  }

  public function getSnapshot($repoName, $name)
  {
    $r = $this->getClient()->snapshot()->get(array('repository' => $repoName, 'snapshot' => $name));
    if (isset($r['snapshots'][0]))
      return $r['snapshots'][0];
    return null;
  }

  public function deleteSnapshot($repoName, $name)
  {
    return $this->getClient()->snapshot()->delete(array('repository' => $repoName, 'snapshot' => $name));
  }

  public function restoreSnapshot($repoName, $name, $params)
  {
    $body = array();
    if (isset($params['indexes']) && !empty($params['indexes']))
      $body['indices'] = $params['indexes'];
    if (isset($params['ignoreUnavailable']))
      $body['ignore_unavailable'] = $params['ignoreUnavailable'];
    if (isset($params['includeGlobalState']))
      $body['include_global_state'] = $params['includeGlobalState'];
    if (isset($params['renamePattern']) && !empty($params['renamePattern']) && $params['renamePattern'] != null)
      $body['rename_pattern'] = $params['renamePattern'];
    if (isset($params['renameReplacement']) && !empty($params['renameReplacement']) && $params['renameReplacement'] != null)
      $body['rename_replacement'] = $params['renameReplacement'];
    $this->getClient()->snapshot()->restore(array(
      'repository' => $repoName,
      'snapshot' => $name,
      'body' => $body
    ));
  }

  public function scroll($queryBody, $index, $mapping, $callback, $context = array())
  {
    $r = $this->getClient()->search(array(
      'index' => $index,
      'type' => $mapping,
      'body' => $queryBody,
      'scroll' => '1ms'
    ));
    if (isset($r['_scroll_id'])) {
      $scrollId = $r['_scroll_id'];
      while (count($r['hits']['hits']) > 0) {
        foreach ($r['hits']['hits'] as $hit) {
          $callback($hit, $context);
        }
        $r = $this->client->scroll(array(
          'scroll_id' => $scrollId,
          'scroll' => '1m'
        ));
      }
    }
  }

  public function customSearch($params)
  {
    return $this->getClient()->search($params);
  }

  /**
   * @param $items
   */
  public function bulkIndex($items)
  {
    $bulkString = '';
    foreach ($items as $item) {
      $data = array('index' => array('_index' => $item['indexName'], '_type' => $item['mappingName']));
      if (isset($item['body']['_id'])) {
        $data['index']['_id'] = $item['body']['_id'];
        unset($item['body']['_id']);
      }
      $bulkString .= json_encode($data) . "\n";
      $bulkString .= json_encode($item['body']) . "\n";
    }
    if (count($items) > 0) {
      $params['index'] = $items[0]['indexName'];
      $params['type'] = $items[0]['mappingName'];
      $params['body'] = $bulkString;
      $this->getClient()->bulk($params);
    }
  }

  private function initSystemMappingMapping($mappingName, $file)
  {
    if ($this->getIndex('.ctsearch') == null) {
      $settingsDefinition = file_get_contents(__DIR__ . '/../Resources/ctsearch_index_settings.json');
      $this->createIndex(new Index('.ctsearch', $settingsDefinition));
    }
    if ($this->getMapping('.ctsearch', $mappingName) == null) {
      $defintion = file_get_contents(__DIR__ . '/../Resources/' . $file);
      $this->updateMapping(new Mapping('.ctsearch', $mappingName, $defintion));
    }
  }

  public function saveUser(User $user)
  {
    $this->initSystemMappingMapping('user', ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_user_definition.json');
    $params = array(
      'index' => '.ctsearch',
      'type' => 'user',
      'id' => $user->getUid(),
      'body' => array(
        'uid' => $user->getUid(),
        'password' => $user->getPassword(),
        'roles' => $user->getRoles(),
        'email' => $user->getEmail(),
        'full_name' => $user->getFullName(),
        'groups' => $user->getGroups(),
      )
    );
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  /**
   * @param string $uid
   * @return User
   */
  function getUser($uid)
  {
    $this->initSystemMappingMapping('user', ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_user_definition.json');
    try {
      $r = $this->getClient()->search(array(
        'index' => '.ctsearch',
        'type' => 'user',
        'body' => array(
          'query' => array(
            'match' => array(
              '_id' => $uid,
            )
          )
        )
      ));
      if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
        $hit = $r['hits']['hits'][0];
        /** @var Datasource $datasource */
        $user = new User($hit['_source']['uid'], $hit['_source']['roles'], $hit['_source']['email'], $hit['_source']['full_name'], $hit['_source']['groups']);
        $user->setPassword($hit['_source']['password']);
        unset($r);
        return $user;
      }
      return null;
    } catch (\Exception $ex) {
      return null;
    }
  }

  /**
   * @return User[]
   */
  function getUsers()
  {
    $this->initSystemMappingMapping('user', ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_user_definition.json');
    $list = array();
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'user',
          'size' => 9999
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          foreach ($r['hits']['hits'] as $hit) {
            $user = new User($hit['_source']['uid'], $hit['_source']['roles'], $hit['_source']['email'], $hit['_source']['full_name'], $hit['_source']['groups']);
            $user->setPassword($hit['_source']['password']);
            $list[] = $user;;
          }
        }
        unset($r);
      } catch (\Exception $ex) {

      }
    }
    return $list;
  }

  /**
   * @return Group[]
   */
  function getGroups()
  {
    $this->initSystemMappingMapping('group', ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_group_definition.json');
    $list = array();
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'group',
          'size' => 9999
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          foreach ($r['hits']['hits'] as $hit) {
            $list[] = new Group($hit['_id'], $hit['_source']['name'], isset($hit['_source']['indexes']) ? $hit['_source']['indexes'] : [], isset($hit['_source']['datasources']) ? $hit['_source']['datasources'] : [], isset($hit['_source']['matching_lists']) ? $hit['_source']['matching_lists'] : [], isset($hit['_source']['dictionaries']) ? $hit['_source']['dictionaries'] : []);
          }
        }
        unset($r);
      } catch (\Exception $ex) {

      }
    }
    return $list;
  }

  /**
   * @return Group
   */
  function getGroup($id)
  {
    $this->initSystemMappingMapping('group', ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_group_definition.json');
    if ($this->getIndex('.ctsearch') != null) {
      try {
        $r = $this->getClient()->search(array(
          'index' => '.ctsearch',
          'type' => 'group',
          'body' => array(
            'query' => array(
              'match' => array(
                '_id' => $id,
              )
            )
          )
        ));
        if (isset($r['hits']['hits']) && count($r['hits']['hits']) > 0) {
          $group = new Group($r['hits']['hits'][0]['_id'], $r['hits']['hits'][0]['_source']['name'], isset($r['hits']['hits'][0]['_source']['indexes']) ? $r['hits']['hits'][0]['_source']['indexes'] : [], isset($r['hits']['hits'][0]['_source']['datasources']) ? $r['hits']['hits'][0]['_source']['datasources'] : [], isset($r['hits']['hits'][0]['_source']['matching_lists']) ? $r['hits']['hits'][0]['_source']['matching_lists'] : [], isset($r['hits']['hits'][0]['_source']['dictionaries']) ? $r['hits']['hits'][0]['_source']['dictionaries'] : []);
        }
        unset($r);
        return $group;
      } catch (\Exception $ex) {
        return null;
      }
    }
    return null;
  }

  public function saveGroup(Group $group)
  {
    $this->initSystemMappingMapping('user', ($this->getServerMajorVersionNumber() >= 5 ? '5-def/' : '') . 'ctsearch_group_definition.json');
    $params = array(
      'index' => '.ctsearch',
      'type' => 'group',
      'id' => $group->getId(),
      'body' => array(
        'name' => $group->getName(),
        'indexes' => $group->getIndexes(),
        'datasources' => $group->getDatasources(),
        'matching_lists' => $group->getMatchingLists(),
        'dictionaries' => $group->getDictionaries(),
      )
    );
    $r = $this->getClient()->index($params);
    $this->getClient()->indices()->flush();
    unset($params);
    return $r;
  }

  /**
   * @param string $id
   */
  public function deleteGroup($id)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'group',
        'id' => $id,
      )
    );
    $this->getClient()->indices()->flush();
  }

  /**
   * @param string $uid
   */
  public function deleteUser($uid)
  {
    $this->getClient()->delete(array(
        'index' => '.ctsearch',
        'type' => 'user',
        'id' => $uid,
      )
    );
    $this->getClient()->indices()->flush();
  }

  public function getSynonymsDictionaries(){
    $allowed_dictionaries = $this->getCurrentUserAllowedDictionaries();
    $dictionaries = [];
    $location = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'synonyms';
    if(realpath($location) && is_writable($location)){
      $files = scandir($location);
      $dictionaries = array();
      foreach($files as $file){
        if(is_file($location . DIRECTORY_SEPARATOR . $file) && ($this->isCurrentUserAdmin() || in_array($file, $allowed_dictionaries))){
          $dictionaries[] = array(
            'name' => $file,
            'path' => realpath($location . DIRECTORY_SEPARATOR . $file)
          );
        }
      }
    }
    return $dictionaries;
  }

}
