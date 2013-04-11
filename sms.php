<?php
require_once 'Services/Twilio.php';
require_once 'lib/Nest.php';


$token = 'myTokenForTwilio'; // Twilio token
$configLocation = '/opt/nest/config.ini'; // Where to load the config from
$url = "http://example.com/nest/sms.php"; // The URL that you gave to Twilio
// Phone numbers allowed to control your Thermostat
$permitted = array(
  '+15555555555', 
  '+15555555555',
);

$validator = new Services_Twilio_RequestValidator($token);

$headers = apache_request_headers();
$headers = $headers['X-Twilio-Signature'];

// Make sure the request is actually coming from Twilio
// and send a 403 if it isn't
if(!$validator->validate($headers, $url, $_POST)){
  header("HTTP/1.0 403 Forbidden");
  echo "Nice try.  But no.\n";
  die();
}

$nest = new Nest($configLocation);


$response = new Services_Twilio_Twiml;
$envelope = array(
  'to' => $_REQUEST['From'],
  'from' => $_REQUEST['To'],
);

if(!in_array($_REQUEST['From'], $permitted)){
  $response->sms("Not permitted from ".$_REQUEST['From'], $envelope);
  exit();
}


if(is_numeric($_REQUEST['Body'])){
  if($_REQUEST['Body'] > 82 || $_REQUEST['Body'] < 58){
    $response->sms($_REQUEST['Body']." seems kind of extreme. Send a different temperature.", $envelope);
    print $response;
    exit();
  }
  try{
    if(f2c($_REQUEST['Body']) != $nest->getTargetTemperature()) {
      $nest->setTargetTemperature(f2c($_REQUEST['Body']));
    } else {
      $response->sms("The target temperature is already set to ".$_REQUEST['Body']." degrees.", $message);
      print $response;
      exit();
    }
  } catch (Exception $e){
    $response->sms("Something went wrong, don't try again.", $envelope);
    print $response;
    die();
  }
  $resp = "Target temperature set to ".$_REQUEST['Body']." degrees.";
  if($nest->isAway())
    $resp .= " (Away mode is currently set)";
  $response->sms($resp, $envelope);
  print $response;
  exit();
}

$body = strtolower($_REQUEST['Body']);

try{
  switch($body){
    case "set away":
      $nest->setAway(true);
      $resp = "Thermostat set to away mode.";
      break;
    case "set not away":
      $nest->setAway(false);
      $resp = "Thermost set to not away mode.";
      break;
    case "status":
      $targetTemp = $nest->getTargetTemperature();
      $conditions = $nest->getIndoorConditions();
      $resp = "Current temperature: ".round(c2f($conditions['temperature']),1)." degrees\n";
      $resp .= "Indoor Humidity: ".$conditions['humidity']."%\n";
      $resp .= "Target temperature: ".round(c2f($targetTemp),1)." degrees";
      if($nest->isAway()){
        $resp .= " (overridden by away mode)";
      } else {
      }
      break;
    default:
      $resp = "Not understood (use set away, set not away, status, or a number)";
      break;
  }


} catch (Exception $e){
  // Tell the user not to try again - this is likely an error in our code.
  $response->sms("Something went wrong.  Don't try again.", $envelope);
  print $response;
  die();
}

$response->sms($resp, $envelope);
print $response;

// TODO: Move these 2 functions somewhere else - maybe in the class?

function f2c($temp){
  return ($temp - 32) * 5/9;
}

function c2f($temp){
  return $temp * 9 / 5 + 32;
}
