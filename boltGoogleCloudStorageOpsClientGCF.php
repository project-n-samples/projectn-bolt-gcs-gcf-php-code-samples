<?php

include_once "boltGoogleCloudStorageOpsClient.php";

use Psr\Http\Message\ServerRequestInterface;

/**
boltGcsOpsClientGcfEntry is the handler function that is invoked by GCF to process an incoming event.

boltGcsOpsClientGcfEntry accepts the following input parameters as part of the event:

1) sdkType - Endpoint to which request is sent. The following values are supported:
    GoogleCloudStorage - The Request is sent to GoogleCloudStorage.
    Bolt - The Request is sent to Bolt, whose endpoint is configured via 'BOLT_URL' environment variable

2) requestType - type of request / operation to be performed. The following requests are supported:
    a) list_objects - list objects
    b) list_buckets - list buckets
    c) get_object_metadata - get object metadata
    d) get_bucket_metadata - get bucket metadata
    e) download_object - download object (md5 hash)
    f) upload_object - upload object
    g) delete_object - delete object

3) bucket - bucket name

4) key - key name

Following are examples of events, for various requests, that can be used to invoke the handler function.
    
a) Listing objects from Bolt bucket:
    {"requestType": "list_objects", "sdkType": "BOLT", "bucket": "<bucket>"}
    
b) Listing buckets from GoogleCloudStorage:
    {"requestType": "list_buckets", "sdkType": "GoogleCloudStorage"}
    
c) Get Bolt object metadata (GET_OBJECT_METADATA):
    {"requestType": "get_object_metadata", "sdkType": "BOLT", "bucket": "<bucket>", "key": "<key>"}
    
d) Check if GS bucket exists (GET_BUCKET_METADATA):
    {"requestType": "get_bucket_metadata","sdkType": "GoogleCloudStorage", "bucket": "<bucket>"}
    
e) Download object (its MD5 Hash) from Bolt:
    {"requestType": "download_object", "sdkType": "BOLT", "bucket": "<bucket>", "key": "<key>"}
    
f) Upload object to Bolt:
    {"requestType": "upload_object", "sdkType": "BOLT", "bucket": "<bucket>", "key": "<key>", "value": "<value>"}
    
g) Delete object from Bolt:
    {"requestType": "delete_object", "sdkType": "BOLT", "bucket": "<bucket>", "key": "<key>"}
*/

function boltGcsOpsClientGcfEntry(ServerRequestInterface $request): void
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
