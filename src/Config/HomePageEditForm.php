<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HomePageEditForm extends FormBase {
  
  protected NlpConfiguration $configurationObj;
  public function __construct( $configurationObj) {
    $this->configurationObj = $configurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): HomePageEditForm
  {
    return new static(
      $container->get('nlpservices.configuration'),
    );
  }
  public function getFormId(): string
  {
    return 'nlpservices_home_page_edit_form';
  }
  
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['note'] = [
      '#markup' => "<p>The purpose of this function is to customize the home page for an election.<br>
  There are three keys possible to reference election information:<br>
  &nbsp;&nbsp;#electionName - the configured name of this election<br>
  &nbsp;&nbsp;#ballotDropDate - the earliest that ballots are mailed to voters<br>
  &nbsp;&nbsp;#electionDate - the date of this election
  </p>",
    ];
    
    $nid = $this->getHomePageNid();
    if (!$nid) {
      $form['message'] = [
        '#markup' => $this->t('No Home page found.'),
      ];
      return $form;
    }
    $node = Node::load($nid);
    $body = $node->get('body')->value ?? '';
    
    $form['body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Home Page HTML'),
      '#format' => 'full_html',
      '#default_value' => $body,
      '#required' => TRUE,
    ];
    
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];
    
    return $form;
  }
  
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $replace = [];
    $electionName = $this->configurationObj->getElectionName();
    if(!empty( $electionName )) {
      $replace['#electionName'] = $electionName;
    }
    $electionDate = $this->configurationObj->getElectionDate(TRUE);
    if(!empty( $electionDate )) {
      $replace['#electionDate'] = $electionDate;
    }
    $ballotDropDate = $this->configurationObj->getBallotDropDate(TRUE);
    if(!empty( $ballotDropDate )) {
      $replace['#ballotDropDate'] = $ballotDropDate;
    }
    
    $nid = $this->getHomePageNid();
    if ($nid) {
      $node = Node::load($nid);
      $body = $form_state->getValue('body');
      
      $bodyValue = strtr($body['value'], $replace);
      
      $node->set('body', [
        'value' => $bodyValue,
        'format' => $body['format'],
      ]);
      try {
        $node->save();
      } catch (Drupal\Core\Entity\EntityStorageException $e) {
        nlp_debug_msg('HomePageEditForm', $e->getMessage());
      }
      $this->messenger()->addStatus($this->t('Home page updated.'));
    }
    else {
      $this->messenger()->addError($this->t('Home page not found.'));
    }
  }
  
  private function getHomePageNid() {
    $nids = Drupal::entityQuery('node')
      ->condition('type', 'page')
      ->condition('title', 'Home')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    return $nids ? reset($nids) : NULL;
  }
}
