<?php

require_once 'HTTP/Request2.php';

/**
 * Nest account class based on the ruby gem written by ericboehs
 * (https://github.com/ericboehs/nest_thermostat)
 *
 * @author Phil Fenstermacher <phillip.fenstermacher@gmail.com>
 * @license @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 */
class Nest {
  private $loginResponse = false;
  private $actionHeaders = false;
  private $config;
  private $structureDeviceMap = false;

  // Store all of the thermostat status information
  public $status = false;
  // Keep the request object for future use
  protected $request = false;

  /**
   * Reads configuration information.
   *
   * @param string $configFile The configuration file to use
   *
   * @access public
   */
  public function __construct($config = "config.ini"){
    if(is_array($config))
      $this->config = $config;
    else
      $this->config = parse_ini_file($config, true);

    $this->getCredentials();
  }

  /**
   * Update the data stored in the status member variable
   *
   * @access public
   */
  public function fetchStatus(){
    $this->request = new HTTP_Request2(
      $this->loginResponse['urls']['transport_url']."/v2/mobile/".$this->loginResponse['user']
    );

    $this->request->setMethod(HTTP_Request2::METHOD_GET);
    $this->request->setConfig($this->config['http']);
    $this->request->setHeader($this->actionHeaders);

    $response = $this->request->send();
    if($response->getStatus() == 200){
      $this->status = json_decode($response->getBody(), true);
    } else {
      throw new Exception("Request Error: ".$response->getBody());
    }
  }

   /**
   * Get login credentials to use to talk to the Nest server
   *
   * @access protected
   */
  protected function getCredentials(){
    if($this->config['cache_credentials']['enabled']){
      // Set a default cache filename if nothing is specified
      if(!isset($this->config['cache_credentials']['file']) ||
            $this->config['cache_credentials']['file'] == '')
        $this->config['cache_credentials']['file'] = 'nest.credentials';

      if(file_exists($this->config['cache_credentials']['file'])){
        // Store the cached credentials in the object
        $json = file_get_contents($this->config['cache_credentials']['file']);
        $this->loginResponse = json_decode($json, true);

        // Have the credentials expired yet?  If they have
        // then get new ones
        $expires = new DateTime($this->loginResponse['expires_in']);
        $now = new DateTime();
        $now->add(new DateInterval('P29D'));
        if($now < $expires){
          $this->prepareActionHeaders();
          return;
        }
      }

      $this->login();
      // Write the credentials to the cache file
      file_put_contents($this->config['cache_credentials']['file'], json_encode($this->loginResponse));
      $this->prepareActionHeaders();

    } else {
      $this->login();
      $this->prepareActionHeaders();
    }
  }

  /**
   * Login and store the server response in loginResponse if
   * the operation is successful.
   *
   * @access private
   */
  private function login(){
    $loginRequest = new HTTP_Request2($this->config['nest_connection']['login_url']);
    $loginRequest->setMethod(HTTP_Request2::METHOD_POST);
    $loginRequest->setConfig($this->config['http']);

    $loginRequest->addPostParameter($this->config['login']);

    // Define headers
    $loginHeaders = array(
      'User-Agent' => $this->config['nest_connection']['user_agent'],
    );

    $loginRequest->setHeader($loginHeaders);

    $response = $loginRequest->send();

    if($response->getStatus() == 200){
      // Save the server response
      $this->loginResponse = json_decode($response->getBody(), true);
      return;
    } else {
      throw new Exception("Server Error: ".$response->getBody());
    }
  }

  /**
   * Convert the server response to headers to use in future actions
   *
   * @access private
   */
  private function prepareActionHeaders(){
    $actionUrl = parse_url($this->loginResponse['urls']['transport_url']);
    $this->actionHeaders = array(
      'Host' => $actionUrl['host'],
      'User-Agent' => $this->config['nest_connection']['user_agent'],
      'Authorization' => "Basic ".$this->loginResponse['access_token'],
      'X-nl-user-id' => $this->loginResponse['userid'],
      'X-nl-protocol-version' => '1',
      'Accept-Language' => 'en-us',
      'Connection' => 'keep-alive',
      'Accept' => '*/*',
    );
  }

  /**
   * Build an array of structures with devices attached
   *
   * @private private
   */
  private function buildStructureDeviceMap(){
    if(!$this->status)
      $this->fetchStatus();
  	$structures = array();
  	foreach($this->status['structure'] as $key => $values){
  	  $devices = array();
  	  foreach($values['devices'] as $device){
  	    $devices[] = str_replace('device.', '', $device);
  	  }
  	  $structures[$key] = $devices;
  	}
  	$this->structureDeviceMap = $structures;
  }

  /**
   * Get a list of structures that the user can access
   *
   * @return array A list of structures that the user can access
   * @access public
   */
  public function getStructures(){
    if(!$this->structureDeviceMap)
      $this->buildStructureDeviceMap();
    $structures = array();
    foreach($this->structureDeviceMap as $struct => $devices){
      $structures[] = $struct;
    }
    return $structures;
  }

  /**
   * Get a list of devices that go with a structure
   *
   * @param string $structureID The structure ID, if omitted then
   *   a list of all devices is returned.
   * @return array A list of devices that match a given structure
   */
  public function getDevices($structureID = null){
    if(!$this->structureDeviceMap)
      $this->buildStructureDeviceMap();
    if($structureID == null){
      $devices = array();
      foreach($this->getStructures() as $struct){
        $devices = array_merge($this->getDevices($struct), $devices);
      }
      return $devices;
    }
    return $this->structureDeviceMap[$structureID];
  }

  /**
   * Send update information to Nest
   *
   * @param array $updates The components of the status array that should be updated
   * @access protected
   */
  protected function sendUpdate($updates){
    foreach($updates as $type => $ids){
      foreach($ids as $id => $data){
        $this->request->setUrl(
          $this->loginResponse['urls']['transport_url'].'/v2/put/'.$type.'.'.$id
          // 'http://127.0.0.1/v2/put/'.$type.'.'.$id
        );
        $this->request->setMethod(HTTP_Request2::METHOD_POST);
        $this->request->setBody(json_encode($data));
        $this->request->setConfig($this->config['http']);
        $this->request->setHeader($this->actionHeaders);

        $response = $this->request->send();

        if($response->getStatus() != 200){
          throw new Exception("Server Error: ".$response->getBody());
        }
      }
    }
  }

  /**
   * Set the target temperature
   *
   * @param float $temperature The new target temperature (in Celsius)
   * @param string $deviceID The device on which the target temperature should
   *   be adjusted.  If omitted, the first device returned by Nest::getDevices(null)
   *   is used.
   * @access public
   */
  public function setTargetTemperature($temperature, $deviceID = null){
    if($deviceID == null){
      $deviceID = $this->getDevices();
      $deviceID = $deviceID[0];
    }
    $msgPayload = array(
      'target_change_pending' => true,
      'target_temperature' => $temperature,
    );
    $msgPayload = array(
      'shared' => array(
        $deviceID => $msgPayload,
      ),
    );
    $this->sendUpdate($msgPayload);
  }

  /**
   * Get the target temperature
   *
   * @param string $deviceID The device to read from
   * @return float The target temperature in degrees celsius
   * @access public
   */
  public function getTargetTemperature($deviceID = null){
    if($deviceID == null){
      $deviceID = $this->getDevices();
      $deviceID = $deviceID[0];
    }
    return $this->status['shared'][$deviceID]['target_temperature'];
  }

  /**
   * Find whether or not a given structure is in the away state
   *
   * @param string $structureID The structure ID being checked
   * @return bool True if the away state is set, otherwise false
   * @access public
   */
  public function isAway($structureID = null){
    if($structureID == null){
      $structureID = $this->getStructures();
      $structureID = $structureID[0];
    }
    return $this->status['structure'][$structureID]['away'];
  }

  /**
   * Set the away status for a given structure
   *
   * @param bool $awayStatus The status to write to Nest
   * @param string $structureID The structure ID of the structure you want
   *   to update.
   * @throws Exception if the Nest server does not return a status code 200
   */
  public function setAway($awayStatus, $structureID = null){
    if($structureID == null){
      $structureID = $this->getStructures();
      $structureID = $structureID[0];
    }
    $updateMessage = array(
      'structure' => array(
        $structureID => array(
          'away' => $awayStatus,
          'away_setter' => 0,
          'away_timestamp' => time(),
          ),
        ),
      );
    $this->sendUpdate($updateMessage);
  }

  /**
   * Get the current indoor temperature and humidity
   */
  public function getIndoorConditions($deviceID = null){
    if($deviceID == null){
      $deviceID = $this->getDevices();
      $deviceID = $deviceID[0];
    }

    return array(
      'humidity' => $this->status['device'][$deviceID]['current_humidity'],
      'temperature' => $this->status['shared'][$deviceID]['current_temperature'],
    );
  }

}

?>
