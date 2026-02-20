<?php

namespace Drupal\nlpservices;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

class NlpEmailConfiguration
{
  protected ConfigFactoryInterface $config;
  protected array $emailConfiguration;
  protected NlpImap $imapObj;
  
  const array CRON_TYPES = [
    'notifications' => 'enableEmailRedirect',
    'minivan' => 'enableMinivan',
    'matchback' => 'enableMatchback',
  ];
  private Config $iConfig;
  
  public function __construct($config, $imapObj ) {
    $this->config = $config;
    $this->imapObj = $imapObj;
    $this->iConfig = $this->config->getEditable('nlpservices.configuration');
    $emailConfiguration = $this->iConfig->get('nlpservices-email-configuration');
    $this->emailConfiguration = empty($emailConfiguration)?[]:$emailConfiguration;
  }
  
  /**
   * @param string $emailType
   * Gets the email configuration for the given email type.
   * Types are 'notifications', 'minivan', and 'matchback'.
   *
   * @return array
   */
  public function getEmailConfiguration(string $emailType): array
  {
    if(empty($this->emailConfiguration[$emailType])) {
      return $this->createCredentials();
    }
    return $this->emailConfiguration[$emailType];
  }
  
  /**
   * saveEmailConfiguration
   * Saves the email configuration for the given email type.
   * Types are 'notifications', 'minivan', and 'matchback'.
   * @param string $emailType
   * @param array $configuration
   *
   * @return void
   */
  public function saveEmailConfiguration(string $emailType, array $configuration): void
  {
    $this->emailConfiguration[$emailType] = $configuration;
    $this->iConfig
      ->set('nlpservices-email-configuration', $this->emailConfiguration)
      ->save();
  }
  
  /**
   * @param array $configuration
   * Checks the configuration for the email credentials.
   * The configuration array must contain either the 'server' or both the 'email' and 'password' keys.
   *
   * @return string
   */
  public function verifyConfiguration(array $configuration): string
  {
    $errorMessage = '';
    if(!empty($configuration['server'])) {
      if(empty($configuration['email']) OR empty($configuration['password'] OR empty($configuration['port']))) {
        $errorMessage = 'You must specify all the email credentials.';
      }
    } elseif(!empty($configuration['email']) OR !empty($configuration['password'] OR !empty($configuration['port']))) {
      $errorMessage = 'You must specify all the email credentials.';
    }
    return $errorMessage;
  }
  
  /**
   * testEmailCredentials
   *
   * Tests the email credentials by attempting to connect to the incoming server.
   *
   * @param string $email
   * @param string $pw
   * @param int $port
   * @param string $incomingServer
   *
   * @return array
   */
  function testEmailCredentials(string $email, string $pw, int $port, string $incomingServer): array
  {
    $emailInfo = [
      'email' => $email,
      'password' => $pw,
      'port' => $port,
      'server' => $incomingServer,
    ];
    $result = $this->imapObj->testImapConnection($emailInfo);
    if($result['result'] == 'success') {
      return ['result'=>TRUE, 'message' => "Connection with these credentials was successful."];
    } else {
      return ['result' => FALSE, 'message' => "Connection attempted failed.<br>" . $result['message']];
    }
  }
  
  /**
   * @param sstring $emailType
   * Checks if the email credentials for the given email type have been validated.
   * Types are 'notifications', 'minivan', and 'matchback'.
   *
   * @return bool
   */
  function emailCredentialsValidated(string $emailType): bool
  {
    $emailConfiguration = $this->getEmailConfiguration($emailType);
    return !empty($emailConfiguration['validated']);
  }
  
  /**
   * createCredentials
   *
   * Creates and returns an array of credentials with default values.
   *
   * @return array An associative array containing the following keys:
   *               - 'email': string, default empty string, representing the email address.
   *               - 'port': string, default empty string, representing the port number.
   *               - 'password': string, default empty string, representing the password.
   *               - 'server': string, default empty string, representing the server address.
   *               - 'validated': bool, default FALSE, indicating whether the credentials are validated.
   *               - 'enable': bool, default FALSE, indicating whether the credentials are enabled.
   */
  public function createCredentials(): array
  {
    return [
      'email' => '',
      'port' => '',
      'password' => '',
      'server' => '',
      'validated' => FALSE,
      'enable' => FALSE,
    ];
  }
  
}