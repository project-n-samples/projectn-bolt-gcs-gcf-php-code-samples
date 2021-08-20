<?php

include_once "boltGoogleCloudStorageOpsClient.php";

use Psr\Http\Message\ServerRequestInterface;

// Here 
function boltGcsValidateObjGcfEntry(ServerRequestInterface $request): void
{
  $body = $request->getBody()->getContents();
  $event = !empty($body) ? json_decode($body, true) : $request->getQueryParams();
  if (!$event) {
    parse_str($body, $event);
  }

  $opsClient = new BoltGoogleCloudStorageOpsClient();

  // Get object using GCS
  $event["requestType"] = RequestType::GetObject;
  $event["sdkType"] = SdkTypes::GCS;
  $gcsGetObjectResponse = $opsClient->processEvent($event);

  // Get object using Bolt
  $event["requestType"] = RequestType::GetObject;
  $event["sdkType"] = SdkTypes::GCS;
  $boltGetObjectResponse = $opsClient->processEvent($event);

  $jsonResponse = json_decode('{}');
  $jsonResponse->gcs_md5 = $gcsGetObjectResponse->md5;
  $jsonResponse->bolt_md5 = $boltGetObjectResponse->md5;

  printf(json_encode($jsonResponse, JSON_PRETTY_PRINT) . PHP_EOL);
}