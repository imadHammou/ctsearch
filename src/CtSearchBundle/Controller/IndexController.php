<?php

namespace CtSearchBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use \CtSearchBundle\CtSearchBundle;
use CtSearchBundle\Classes\IndexManager;
use CtSearchBundle\Classes\Index;
use CtSearchBundle\Classes\Mapping;
use \Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\Translator;

class IndexController extends Controller {

  /**
   * @Route("/indexes", name="indexes")
   */
  public function listIndexesAction(Request $request) {
    $info = IndexManager::getInstance()->getElasticInfo();
    ksort($info);
    return $this->render('ctsearch/indexes.html.twig', array(
        'title' => $this->get('translator')->trans('Indexes'),
        'main_menu_item' => 'indexes',
        'indexes' => $info,
    ));
  }

  /**
   * @Route("/indexes/add", name="index-add")
   */
  public function addIndexAction(Request $request) {
    return $this->getIndexForm($request, $this->container->getParameter('ct_search.es_url'), true);
  }

  /**
   * @Route("/indexes/edit", name="index-edit")
   */
  public function editIndexAction(Request $request) {
    if ($request->get('index_name') != null) {
      return $this->getIndexForm($request, $this->container->getParameter('ct_search.es_url'), false);
    } else {
      CtSearchBundle::addSessionMessage($this, 'error', $this->get('translator')->trans('No index provided'));
      return $this->redirect($this->generateUrl('indexes'));
    }
  }

  /**
   * @Route("/indexes/delete", name="index-delete")
   */
  public function deleteIndexAction(Request $request) {
    if ($request->get('index_name') != null) {
      $index = new Index($request->get('index_name'));
      IndexManager::getInstance()->deleteIndex($index);
      CtSearchBundle::addSessionMessage($this, 'status', $this->get('translator')->trans('Index has been deleted'));
      return $this->redirect($this->generateUrl('indexes'));
    } else {
      CtSearchBundle::addSessionMessage($this, 'error', $this->get('translator')->trans('No index provided'));
      return $this->redirect($this->generateUrl('indexes'));
    }
  }

  /**
   * @Route("/indexes/edit-mapping", name="index-edit-mapping")
   */
  public function editMappingAction(Request $request) {
    if ($request->get('index_name') != null && $request->get('mapping_name') != null) {
      return $this->getMappingForm($request, false);
    } else {
      CtSearchBundle::addSessionMessage($this, 'error', $this->get('translator')->trans('No index or mapping provided'));
      return $this->redirect($this->generateUrl('indexes'));
    }
  }

  /**
   * @Route("/indexes/add-mapping", name="index-add-mapping")
   */
  public function addMappingAction(Request $request) {
    if ($request->get('index_name') != null) {
      return $this->getMappingForm($request, true);
    } else {
      CtSearchBundle::addSessionMessage($this, 'error', $this->get('translator')->trans('No index or mapping provided'));
      return $this->redirect($this->generateUrl('indexes'));
    }
  }

  private function getIndexForm($request, $esUrl, $add) {
    if ($add) {
      $index = new Index();
    } else {
      $index = IndexManager::getInstance()->getIndex($request->get('index_name'));
    }
    $form = $this->createFormBuilder($index)
      ->add('indexName', TextType::class, array(
        'label' => $this->get('translator')->trans('Index name'),
        'disabled' => !$add,
        'required' => true
      ))
      ->add('settings', TextareaType::class, array(
        'label' => $this->get('translator')->trans('Settings (JSON syntax)'),
      ))
      ->add('create', SubmitType::class, array('label' => $add ? $this->get('translator')->trans('Create index') : $this->get('translator')->trans('Update index')))
      ->getForm();

    $form->handleRequest($request);

    if ($form->isValid()) {
      $index = $form->getData();
      try {
        if ($add) {
          IndexManager::getInstance()->createIndex($index);
          CtSearchBundle::addSessionMessage($this, 'status', $this->get('translator')->trans('Index has been created'));
        } else {
          IndexManager::getInstance()->updateIndex($index);
          CtSearchBundle::addSessionMessage($this, 'status', $this->get('translator')->trans('Index has been updated'));
        }
        return $this->redirect($this->generateUrl('indexes'));
      } catch (Exception $ex) {
        CtSearchBundle::addSessionMessage($this, 'error', $this->get('translator')->trans('An error as occured: ') . $ex->getMessage());
      }
    }

    return $this->render('ctsearch/indexes.html.twig', array(
        'title' => $add ? $this->get('translator')->trans('Add an index') : $this->get('translator')->trans('Edit index settings'),
        'main_menu_item' => 'indexes',
        'form' => $form->createView(),
    ));
  }

  private function getMappingForm($request, $add) {
    if ($add) {
      $mapping = new Mapping($request->get('index_name'), '');
    } else {
      $mapping = IndexManager::getInstance()->getMapping($request->get('index_name'), $request->get('mapping_name'));
    }
    $analyzers = IndexManager::getInstance()->getAnalyzers($request->get('index_name'));
    $fieldTypes = IndexManager::getInstance()->getFieldTypes();
    $dateFormats = IndexManager::getInstance()->getDateFormats();
    $form = $this->createFormBuilder($mapping)
      ->add('indexName', TextType::class, array(
        'label' => $this->get('translator')->trans('Index name'),
        'disabled' => true,
        'required' => true
      ))
      ->add('mappingName', TextType::class, array(
        'label' => $this->get('translator')->trans('Mapping name'),
        'disabled' => !$add,
        'required' => true
      ))
      ->add('wipeData', CheckboxType::class, array(
        'label' => $this->get('translator')->trans('Wipe data?'),
        'required' => false
      ))
      ->add('mappingDefinition', TextareaType::class, array(
        'label' => $this->get('translator')->trans('Mapping definition'),
        'required' => true
      ))
      ->add('dynamicTemplates', TextareaType::class, array(
        'label' => $this->get('translator')->trans('Dynamic templates'),
        'required' => false
      ))
      ->add('save', SubmitType::class, array('label' => $this->get('translator')->trans('Save mapping')))
      ->getForm();
    $form->handleRequest($request);

    if ($form->isValid()) {
      /** @var Mapping $mapping */
      $mapping = $form->getData();
      if($mapping->getDynamicTemplates() == ''){
        $mapping->setDynamicTemplates(NULL);
      }
      try {
        IndexManager::getInstance()->updateMapping($mapping);
        CtSearchBundle::addSessionMessage($this, 'status', $this->get('translator')->trans('Mapping has been updated'));
        return $this->redirect($this->generateUrl('indexes'));
      } catch (Exception $ex) {
        CtSearchBundle::addSessionMessage($this, 'error', $this->get('translator')->trans('An error as occured: ') . $ex->getMessage());
      }
    }
    $vars = array(
      'title' => $this->get('translator')->trans('Edit mapping'),
      'main_menu_item' => 'indexes',
      'form' => $form->createView(),
      'analyzers' => $analyzers,
      'fieldTypes' => $fieldTypes,
      'dateFormats' => $dateFormats,
      'serverVersion' => IndexManager::getInstance()->getServerMajorVersionNumber()
    );
    return $this->render('ctsearch/indexes.html.twig', $vars);
  }

  /**
   * @Route("/test-service", name="test-service")
   */
  public function testServiceAction(Request $request) {
    $data = array(
      'op' => 'test',
      'domain' => 'core-techs.fr'
    );
    $r = $this->getRestData('http://localhost:8080/CtSearchWebCrawler/service', $data);
    return new \Symfony\Component\HttpFoundation\Response(json_encode($r), 200, array('Content-type' => 'text/html'));
  }

  private function getRestData($url, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . urlencode(json_encode($data)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r, true);
  }

  /**
   * @Route("/indexes/mapping-stat/{index_name}/{mapping_name}", name="index-mapping-stat")
   */
  public function mappingStatAction(Request $request, $index_name, $mapping_name) {
    $mapping = IndexManager::getInstance()->getMapping($index_name, $mapping_name);
    $data = array(
      'docs' => 0,
      'fields' => 0
    );
    if ($mapping != null) {
      $res = IndexManager::getInstance()->search($index_name, '{"query":{"match_all":{"boost":1}}}', 0, 0, $mapping_name);
      if (isset($res['hits']['total']) && $res['hits']['total'] > 0) {
        $data['docs'] = $res['hits']['total'];
      }
      $data['fields'] = count(json_decode($mapping->getMappingDefinition(), TRUE));
    }
    return new \Symfony\Component\HttpFoundation\Response(json_encode($data), 200, array('Content-type' => 'application/json'));
  }

  /**
   * @Route("/indexes/synonyms", name="synonyms-list")
   * @param Request $request
   * @return Response
   */
  public function listSynonymsDictionariesAction(Request $request){
    $vars = array(
      'title' => $this->get('translator')->trans('Synonyms'),
      'main_menu_item' => 'indexes'
    );

    $location = $this->container->getParameter('ct_search.synonyms_path');
    /** @var Translator $translator */
    $translator = $this->get('translator');
    if($location == null){
      CtSearchBundle::addSessionMessage($this, 'error', $translator->trans('Parameter ctsearch_synonyms_path must be set'));
    }
    else {
      if (!realpath($location)) {
        CtSearchBundle::addSessionMessage($this, 'error', $translator->trans('Path @path does not exist', array('@path' => $location)));
      } else {
        if (!is_writable($location)) {
          CtSearchBundle::addSessionMessage($this, 'error', $translator->trans('Path <strong>@path</strong> is not writable', array('@path' => realpath($location))));
        }
        $vars['dictionaries'] = IndexManager::getInstance()->getSynonymsDictionaries();
      }
    }

    return $this->render('ctsearch/synonyms.html.twig', $vars);
  }

  /**
   * @Route("/indexes/synonyms/add", name="synonyms-add")
   * @Route("/indexes/synonyms/edit/{fileName}", name="synonyms-edit")
   * @param Request $request
   * @return Response
   */
  public function addSynonymsDictionariesAction(Request $request, $fileName = null){
    $vars = array(
      'title' => $this->get('translator')->trans('Synonyms'),
      'sub_title' => $fileName == null ? $this->get('translator')->trans('New dictionary') : $this->get('translator')->trans('Edit dictionary'),
      'main_menu_item' => 'indexes'
    );

    $location = $this->container->getParameter('ct_search.synonyms_path');

    $data = array(
      'name' => $fileName != null ? $fileName : '',
      'content' => $fileName != null ? file_get_contents($location . DIRECTORY_SEPARATOR . $fileName) : '# Blank lines and lines starting with pound are comments.

# Explicit mappings match any token sequence on the LHS of "=>"
# and replace with all alternatives on the RHS.  These types of mappings
# ignore the expand parameter in the schema.
# Examples:
i-pod, i pod => ipod,
sea biscuit, sea biscit => seabiscuit

# Equivalent synonyms may be separated with commas and give
# no explicit mapping.  In this case the mapping behavior will
# be taken from the expand parameter in the schema.  This allows
# the same synonym file to be used in different synonym handling strategies.
# Examples:
ipod, i-pod, i pod
foozball , foosball
universe , cosmos

# If expand==true, "ipod, i-pod, i pod" is equivalent
# to the explicit mapping:
ipod, i-pod, i pod => ipod, i-pod, i pod
# If expand==false, "ipod, i-pod, i pod" is equivalent
# to the explicit mapping:
ipod, i-pod, i pod => ipod

# Multiple synonym mapping entries are merged.
foo => foo bar
foo => baz
# is equivalent to
foo => foo bar, baz',
    );
    $form = $this->createFormBuilder($data)
      ->add('name', TextType::class, array(
        'label' => $this->get('translator')->trans('Name'),
        'required' => true,
      ))
      ->add('content', TextareaType::class, array(
        'label' => $this->get('translator')->trans('Content'),
        'required' => true,
      ))
      ->add('submit', SubmitType::class, array(
        'label' => $this->get('translator')->trans('Save')
      ))
      ->getForm();

    $form->handleRequest($request);

    if($form->isValid()){
      $name = $form->getData()['name'];
      $name = str_replace('.txt', '', $name);
      $name = preg_replace('/\W/i', '_', strtolower($name));
      $file = $location . DIRECTORY_SEPARATOR . $name . '.txt';
      $translator = $this->get('translator');
      if (!file_exists($file) || rtrim($fileName, '.txt') == $name) {
        file_put_contents($file, $form->getData()['content']);
        CtSearchBundle::addSessionMessage($this, 'status', $translator->trans('File <strong>@path</strong> has been updated', array('@path' => realpath($file))));
        if($fileName !=null && rtrim($fileName, '.txt') != $name) {
          unlink($location . DIRECTORY_SEPARATOR . $fileName);
        }
        return $this->redirectToRoute('synonyms-list');
      } else {
        CtSearchBundle::addSessionMessage($this, 'error', $translator->trans('File <strong>@path</strong> already exists', array('@path' => realpath($file))));
      }
    }

    $vars['form'] = $form->createView();

    return $this->render('ctsearch/synonyms.html.twig', $vars);
  }

  /**
   * @Route("/indexes/synonyms/delete/{fileName}", name="synonyms-delete")
   * @param Request $request
   * @return Response
   */
  public function deleteSynonymsDictionariesAction(Request $request, $fileName){
    $location = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'synonyms';
    $file = $location . DIRECTORY_SEPARATOR . $fileName;
    unlink($file);
    $translator = $this->get('translator');
    CtSearchBundle::addSessionMessage($this, 'status', $translator->trans('File <strong>@path</strong> has been deleted', array('@path' => realpath($file))));
    return $this->redirectToRoute('synonyms-list');
  }

}
