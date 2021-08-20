<?php

include_once "boltGoogleCloudStorageOpsClient.php";

include_once "commonFunctions.php";

use Psr\Http\Message\ServerRequestInterface;

/**
 * <summary>
 * boltAutoHealTestGcfEntry is the handler function that is invoked by Google Cloud Function to process an incoming event for 
 * performing auto-heal tests.
 * lambdaHandler accepts the following input parameters as part of the event:
 * 1) bucket - bucket name
 * 2) key - key name
 * </summary>
 * <param name="$request">incoming event</param>
 * <returns>time taken to auto-heal</returns>
 */
function boltAutoHealTestGcfEntry(ServerRequestInterface $request): void
{
  $body = $request->getBody()->getContents();
  $event = !empty($body) ? json_decode($body, true) : $request->getQueryParams();
  if (!$event) {
    parse_str($body, $event);
  }

  $WAIT_TIME_BETWEEN_RETRIES = 2;

  $opsClient = new BoltGoogleCloudStorageOpsClient();

  $event["requestType"] = RequestType::GetObject;
  $event["sdkType"] = SdkTypes::Bolt;

  $isObjectHealed = false;
  $starttime = microtime(true);
  while (!$isObjectHealed) {
    try {
      $opsClient->processEvent($event);
      $isObjectHealed = true;
    } catch (Exception $ex) {
      info_log("Waiting...");
      sleep($WAIT_TIME_BETWEEN_RETRIES);
      info_log("Re-trying Get Object...");
    }
  }
  $endtime = microtime(true);
  $timediff = $endtime - $starttime;

  $jsonResponse = json_decode('{}');
  $jsonResponse->auto_heal_time = $timediff;
  printf(json_encode($jsonResponse, JSON_PRETTY_PRINT) . PHP_EOL);
}
