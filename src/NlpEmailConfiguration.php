<?php

namespace Drupal\nlpservices;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;

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
    $this->emailConfiguration = empty($emailConfiguration) ? [] : $emailConfiguration;
  }

  /**
   * getEmailConfiguration
   *
   * Retrieves the email configuration for a given email type.
   *
   * @param string $emailType The type of email for which the configuration is requested.
   * @return array An array containing the email configuration. If no configuration exists for the specified type, default credentials will be created and returned.
   */
  public function getEmailConfiguration(string $emailType): array
  {
    if (empty($this->emailConfiguration[$emailType])) {
      return $this->createCredentials();
    }
    return $this->emailConfiguration[$emailType];
  }

  /**
   * saveEmailConfiguration
   *
   * Saves the email configuration for a given email type.
   *
   * @param string $emailType The type of email for which the configuration is being saved.
   * @param array $configuration An array containing the email configuration to be saved.
   */
  public function saveEmailConfiguration(string $emailType, array $configuration): void
  {
    $this->emailConfiguration[$emailType] = $configuration;
    $this->iConfig
      ->set('nlpservices-email-configuration', $this->emailConfiguration)
      ->save();
  }

  /**
   * verifyConfiguration
   *
   * Verifies the email configuration to ensure that all necessary credentials are provided.
   *
   * @param array $configuration An array containing the email configuration to be verified.
   * @return string An error message if the configuration is invalid, or an empty string if the configuration is valid.
   */
  public function verifyConfiguration(array $configuration): string
  {
    $errorMessage = '';
    if (!empty($configuration['server'])) {
      if (empty($configuration['email']) || empty($configuration['password']) || empty($configuration['port'])) {
        $errorMessage = 'You must specify all the email credentials.';
      }
    } elseif (!empty($configuration['email']) || !empty($configuration['password']) || !empty($configuration['port'])) {
      $errorMessage = 'You must specify all the email credentials.';
    }
    return $errorMessage;
  }

  /**
   * testEmailCredentials
   *
   * Tests the provided email credentials by attempting to establish a connection to the email server.
   *
   * @param string $email The email address to be tested.
   * @param string $pw The password associated with the email address.
   * @param int $port The port number to be used for the connection.
   * @param string $incomingServer The incoming server address to be used for the connection.
   * @return array An array containing the result of the connection attempt and a message indicating success or failure.
   */
  public function testEmailCredentials(string $email, string $pw, int $port, string $incomingServer): array
  {
    $emailInfo = [
      'email' => $email,
      'password' => $pw,
      'port' => $port,
      'server' => $incomingServer,
    ];
    $result = $this->imapObj->testImapConnection($emailInfo);
    if ($result['result'] === 'success') {
      return ['result' => TRUE, 'message' => "Connection with these credentials was successful."];
    } else {
      return ['result' => FALSE, 'message' => "Connection attempted failed.<br>" . $result['message']];
    }
  }

  /**
   * emailCredentialsValidated
   *
   * Checks if the email credentials for a given email type have been validated.
   *
   * @param string $emailType The type of email for which the validation status is being checked.
   * @return bool TRUE if the email credentials have been validated, FALSE otherwise.
   */
  public function emailCredentialsValidated(string $emailType): bool
  {
    $emailConfiguration = $this->getEmailConfiguration($emailType);
    return !empty($emailConfiguration['validated']);
  }

  /**
   * createCredentials
   *
   * Creates a default email configuration with empty credentials and validation status set to FALSE.
   *
   * @return array An array containing the default email configuration.
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
