<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a block with a simple text.
 *
 * @Block(
 *   id = "nlpervices_login_block",
 *   admin_label = @Translation("NLP Login"),
 * )
 */
class NlpLoginBlock extends BlockBase {
  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    return [
      '#markup' => $this->t('
      <p><span style="font-size: medium; ">Up and to the right, click on the <u>Log in</u> link to either log in as a
      Neighborhood Leader to report the results of a canvass or log in as a coordinator to assist our Neighborhood
      Leaders.</span></p>
      <p><span style="font-size: medium; color: blue; font-style: italic;" >By logging in, you are agreeing to keep
      the information secure and not to share it with any unauthorized person. </span></p>
      '),
    ];
  }
  
  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultReasonInterface|AccessResult
  {
    
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }
  
  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    $config = $this->getConfiguration();
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $this->configuration['my_block_settings'] = $form_state->getValue('my_block_settings');
  }
}