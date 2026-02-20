<?php

namespace Drupal\nlpservices;

class NlpEncryption
{
  /**
   * @param $action
   * @param $string
   * @return string - the decrypted string.
   */
  public function encrypt_decrypt($action, $string): string
  {
    if(empty($string)) {return '';}
    $output = '';
    $method = "AES-256-CBC";
    $secretKey = 'In order to create a more perfect union';
    $secretIv = 'Kamala Harris';
    $key = hash('sha256', $secretKey);
    $iv = substr(hash('sha256', $secretIv), 0, 16);
    
    if ( $action == 'encrypt' ) {
      $output = openssl_encrypt($string, $method, $key, 0, $iv);
      $output = base64_encode($output);
    } else if( $action == 'decrypt' ) {
      $output = openssl_decrypt(base64_decode($string), $method, $key, 0, $iv);
    }
    return $output;
  }
  
}