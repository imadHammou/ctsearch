<?php

namespace CtSearchBundle\Datasource;

use \CtSearchBundle\CtSearchBundle;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use \CtSearch\ClientBundle\Classes\CurlClient;

class DrupalCtExport extends Datasource {

  protected $drupalHost;
  protected $contentType;

  public function getSettings() {
    return array(
      'drupalHost' => $this->getDrupalHost() != null ? $this->getDrupalHost() : '',
      'contentType' => $this->getContentType() != null ? $this->getContentType() : '',
    );
  }

  public function execute($execParams = null) {
    try {
      $count = 0;
      if(isset($execParams['xml'])){
        $xml = simplexml_load_string($execParams['xml']);
        $this->processXML($xml, $count);
      }
      else{
        $url = 'http://' . $this->getDrupalHost() . '/ct/export';
        if($this->getContentType() != null && strlen($this->getContentType()) > 0){
          $url .= '?types=' . $this->getContentType();
          $url_sep = '&';
        }
        else{
          $url_sep = '?';
        }
        if ($this->getOutput() != null) {
          $this->getOutput()->writeln('Harvesting url ' . $url);
        }

        $curlClient = new CurlClient($url);
        $response = $curlClient->getResponse();

        if (!empty($response['data'])) {
          $xml = simplexml_load_string($response['data']);
        } else {
          $xml = false;
        }

        $page = 1;
        while(count($xml->xpath('/entities/entity')) > 0){
          $this->processXML($xml, $count);
          $page++;

          $curlClient = new CurlClient($url . $url_sep . 'page=' . $page);
          $response = $curlClient->getResponse();

          if (!empty($response['data'])) {
            $xml = simplexml_load_string($response['data']);
          } else {
            $xml = false;
          }
          
          if ($this->getOutput() != null) {
            $this->getOutput()->writeln('Harvesting url ' . $url . $url_sep . 'page=' . $page);
          }
        }
      }
    } catch (Exception $ex) {
      print $ex->getMessage();
    }

    if ($this->getOutput() != null) {
      $this->getOutput()->writeln('Found ' . $count . ' documents');
    }
    if ($this->getController() != null) {
      CtSearchBundle::addSessionMessage($this->getController(), 'status', 'Found ' . $count . ' documents');
    }
    parent::execute($execParams);
  }
  
  /**
   * 
   * @param \SimpleXMLElement $xml
   */
  private function processXML($xml, &$count){
    foreach ($xml->xpath('/entities/entity') as $entity) {
      /* @var $node \SimpleXMLElement */
      $id = count($entity->xpath('@id')) > 0 ? (string)$entity->xpath('@id')[0] : null;
      $export_id = count($entity->xpath('export-id')) > 0 ? (string)$entity->xpath('export-id')[0] : null;
      $entity_type = count($entity->xpath('entity-type')) > 0 ? (string)$entity->xpath('entity-type')[0] : null;
      $bundle = count($entity->xpath('bundle')) > 0 ? (string)$entity->xpath('bundle')[0] : null;

      $types = $this->getContentType();
      $criteria = [];
      foreach(explode('||', $types) as $et){
        if(count(explode('|', $et)) == 2){
          $ett = explode('|', $et)[0];
          $bundles = explode(',', explode('|', $et)[1]);
          $criteria[$ett] = $bundles;
        }
      }

      $match = false;
      foreach($criteria as $et => $bundles){
        if($et == $entity_type){
          foreach($bundles as $b){
            if($b == '*' || $b == $bundle){
              $match = true;
            }
          }
        }
      }

      if($match && $id != null && $export_id != null){
        if ($this->getOutput() != null) {
          $this->getOutput()->writeln(($count + 1) . '/ Indexing ' . $export_id . ' ==> Type = ' . (string)$entity->xpath('bundle')[0]);
        }
        $this->index(array(
          'id' => $id,
          'export_id' => $export_id,
          'xml' => simplexml_load_string($entity->asXML()),
        ));
        $count++;
      }
    }
  }

  public function getSettingsForm() {
    if ($this->getController() != null) {
      $formBuilder = parent::getSettingsForm();
      $formBuilder->add('drupalHost', TextType::class, array(
            'label' => $this->getController()->get('translator')->trans('Drupal Host'),
            'required' => true
          ))
          ->add('contentType', TextType::class, array(
            'label' => $this->getController()->get('translator')->trans('Content type restriction'),
            'required' => false
          ))
          ->add('ok', SubmitType::class, array('label' => $this->getController()->get('translator')->trans('Save')));
      return $formBuilder;
    } else {
      return null;
    }
  }

  public function getExcutionForm() {
    $formBuilder = $this->getController()->createFormBuilder()
        ->add('ok', SubmitType::class, array('label' => $this->getController()->get('translator')->trans('Execute')));
    return $formBuilder;
  }

  public function getDatasourceDisplayName() {
    return 'Drupal Ct Export';
  }

  public function getFields() {
    return array(
      'id',
      'export_id',
      'xml',
    );
  }

  function getDrupalHost() {
    return $this->drupalHost;
  }

  function getContentType() {
    return $this->contentType;
  }

  function setDrupalHost($drupalHost) {
    $this->drupalHost = $drupalHost;
  }

  function setContentType($contentType) {
    $this->contentType = $contentType;
  }

}
