<?php

namespace Drupal\nlpservices\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\DatabaseFunctions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class DatabaseSchemaForm extends FormBase
{

  protected DatabaseFunctions $databaseFunctions;

  public function __construct($databaseFunctions)
  {
    $this->databaseFunctions = $databaseFunctions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DatabaseSchemaForm
  {
    return new static(
      $container->get('nlpservices.database_functions'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_database_schema_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $results = $form_state->get('results');
    $action  = $form_state->get('action');

    if ($results !== NULL) {
      if (empty($results)) {
        $text = $action === 'verify'
          ? $this->t('All tables and fields match the schema.')
          : $this->t('No repairs were needed.');
        $markup = '<p>' . $text . '</p>';
      } else {
        $items  = array_map(fn($r) => '<li>' . Html::escape($r) . '</li>', $results);
        $markup = '<ul>' . implode('', $items) . '</ul>';
      }
      $form['results'] = [
        '#type'   => 'markup',
        '#markup' => $markup,
      ];
    }

    $form['verify'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Verify Schema'),
      '#submit' => ['::verifySubmit'],
    ];

    $form['repair'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Repair Schema'),
      '#submit' => ['::repairSubmit'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function verifySubmit(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('results', $this->databaseFunctions->verifySchema());
    $form_state->set('action', 'verify');
    $form_state->setRebuild();
  }

  public function repairSubmit(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('results', $this->databaseFunctions->repairSchema());
    $form_state->set('action', 'repair');
    $form_state->setRebuild();
  }

}
