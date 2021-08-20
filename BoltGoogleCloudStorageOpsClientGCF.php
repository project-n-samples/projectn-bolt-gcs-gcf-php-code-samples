<?php

include_once "boltGoogleCloudStorageOpsClient.php";

use Psr\Http\Message\ServerRequestInterface;

function boltGoogleCloudStorageOpsClient(ServerRequestInterface $request): void
{
  $body = $request->getBody()->getContents();
  $event = !empty($body) ? json_decode($body, true) : $request->getQueryParams();
  if (!$event) {
    parse_str($body, $event);
  }
  $opsClient = new BoltGoogleCloudStorageOpsClient();
  $response = $opsClient->processEvent($event);
  printf(json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL);
}
