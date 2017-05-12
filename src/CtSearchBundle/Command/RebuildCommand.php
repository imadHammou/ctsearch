<?php
/**
 * Created by PhpStorm.
 * User: louis
 * Date: 26/08/2016
 * Time: 14:59
 */

namespace CtSearchBundle\Command;


use CtSearchBundle\Classes\IndexManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildCommand extends ContainerAwareCommand
{
  protected function configure(){
    $this
      ->setName('ctsearch:rebuild')
      ->setDescription('Rebuild index')
      ->addArgument('source', InputArgument::REQUIRED, 'Source')
      ->addArgument('target', InputArgument::REQUIRED, 'Target')
    ;
  }
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $source = $input->getArgument('source');
    $target = $input->getArgument('target');
    if(count(explode('.', $source)) == 2 && count(explode('.', $target)) == 2){
      $indexSource = explode('.', $source)[0];
      $mappingSource = explode('.', $source)[1];
      IndexManager::getInstance()->scroll(array(
        'query' => array(
          'match_all' => array(' boost' => '1.0')
        )
      ), $indexSource, $mappingSource, function($hits, &$context){
        $context['count'] += count($hits);
        $indexTarget = explode('.', $context['target'])[0];
        $mappingTarget = explode('.', $context['target'])[1];
        $items = [];
        foreach($hits as $hit){
          $doc = $hit['_source'];
          $doc['_id'] = $hit['_id'];
          $items[] = array(
            'indexName' => $indexTarget,
            'mappingName' => $mappingTarget,
            'body' => $doc
          );
        }
        IndexManager::getInstance()->bulkIndex($items);
        print 'Total indexed = ' . $context['count'] . PHP_EOL;
      }, array(
        'count' => 0,
        'target' => $target,
      ), 500);
      IndexManager::getInstance()->flush();
    }
    else{
      $output->writeln('Incorrect source and/or target');
    }
  }

  private function iterate($index_name, $mapping, $from, $size, $callback){
    $res = IndexManager::getInstance()->search($index_name, '{"query":{"match_all":{}}}', $from, $size, $mapping);

    if(isset($res['hits']['hits'])) {
      foreach ($res['hits']['hits'] as $index => $hit) {
        $callback($index + $from + 1, $hit);
      }
      $from += $size;
      if($res['hits']['total'] > $from){
        $this->iterate($index_name, $mapping, $from, $size, $callback);
      }
    }
  }
}