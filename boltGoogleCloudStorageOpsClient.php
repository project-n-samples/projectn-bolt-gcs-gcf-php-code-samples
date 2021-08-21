<?php

use Google\Cloud\Storage\StorageClient;

include_once "commonFunctions.php";

/**
 * Enum SdkTypes
 */
abstract class SdkTypes
{
  const Bolt = "BOLT";
  const GCS = "GCS"; // Google Cloud Storage
}

/**
 * Enum RequestTypes
 */
abstract class RequestType
{
  const ListObjects = "LIST_OBJECTS";
  const GetObject = "GET_OBJECT";
  const GetObjectTTFB = "GET_OBJECT_TTFB"; // This is only for Perf
  const GetObjectMetadata = "GET_OBJECT_METADATA";
  const ListBuckets = "LIST_BUCKETS";
  const GetBucketMetadata = "GET_BUCKET_METADATA";
  const UploadObject = "UPLOAD_OBJECT";
  const DownloadObject = "DOWNLOAD_OBJECT";
  const DeleteObject = "DELETE_OBJECT";

  const GetObjectPassthrough = "GET_OBJECT_PASSTHROUGH"; // This is only for Perf
  const GetObjectPassthroughTTFB = "GET_OBJECT_PASSTHROUGH_TTFB"; // This is only for Perf
  const All = "ALL"; // This is only for Perf
}
class BoltGoogleCloudStorageOpsClient
{
  function __construct()
  {
  }

  function processEvent($event)
  {
    $event["sdkType"] = $event["sdkType"] ? strtoupper($event["sdkType"]) : $event["sdkType"];
    $event["requestType"] = $event["requestType"] ? strtoupper($event["requestType"]) : $event["requestType"];
    info_log("event: " . json_encode($event, JSON_PRETTY_PRINT) . PHP_EOL);

    $client = $event["sdkType"] === SdkTypes::Bolt
      ? new StorageClient(["apiEndpoint" => getBoltURL()])
      : new StorageClient();

    switch ($event["requestType"]) {
      case RequestType::ListBuckets:
        return $this->listBuckets($client);
      case RequestType::ListObjects:
        return $this->listObjects($client, $event["bucket"]);
      case RequestType::GetBucketMetadata:
        return $this->getBucketMetadata($client, $event["bucket"]);
      case RequestType::GetObjectMetadata:
        return $this->getObjectMetadata($client, $event["bucket"], $event["key"]);
      case RequestType::UploadObject:
        return $this->uploadObject($client, $event["bucket"], $event["key"], $event["value"]);
      case RequestType::DownloadObject:
        return $this->downloadObject($client, $event["bucket"], $event["key"], $event["isForStat"] === "true" || $event["isForStats"] === true);
      case RequestType::GetObject:
        return $this->downloadObject($client, $event["bucket"], $event["key"], $event["isForStat"] === "true" || $event["isForStats"] === true);
      case RequestType::GetObjectTTFB:
        return $this->downloadObject($client, $event["bucket"], $event["key"], $event["isForStat"] === "true" || $event["isForStats"] === true, true);
      case RequestType::DeleteObject:
        return $this->deleteObject($client, $event["bucket"], $event["key"]);
    }
  }

  function listBuckets($client)
  {
    $buckets = array();
    foreach ($client->buckets() as $bucket) {
      $buckets[] = $bucket->name();
    }
    $jsonResponse = json_decode('{}');
    $jsonResponse->buckets = $buckets;
    return $jsonResponse;
  }

  function listObjects($client, $bucketName)
  {
    $bucket = $client->bucket($bucketName);
    $objects = array();
    foreach ($bucket->objects() as $object) {
      $objects[] = $object->name();
    }
    $jsonResponse = json_decode('{}');
    $jsonResponse->objects = $objects;
    return $jsonResponse;
  }

  function getBucketMetadata($client, $bucketName)
  {
    $bucket = $client->bucket($bucketName);
    $info = $bucket->info();

    $jsonResponse = json_decode('{}');

    if (isset($info['name'])) {
      $jsonResponse->name = $info['name'];
    }
    if (isset($info['location'])) {
      $jsonResponse->location = $info['location'];
    }
    if (isset($info['storageClass'])) {
      $jsonResponse->storageClass = $info['storageClass'];
    }
    if (isset($info['versioningEnabled'])) {
      $jsonResponse->versioningEnabled = $info['versioningEnabled'];
    }

    return $jsonResponse;
  }

  function getObjectMetadata($client, $bucketName, $objectName)
  {
    $bucket = $client->bucket($bucketName);
    $object = $bucket->object($objectName);
    $info = $object->info();

    $jsonResponse = json_decode('{}');

    if (isset($info['name'])) {
      $jsonResponse->name = $info['name'];
    }
    if (isset($info['bucket'])) {
      $jsonResponse->bucket = $info['bucket'];
    }
    if (isset($info['expiration'])) {
      $jsonResponse->retentionExpirationTime = $info['retentionExpirationTime'];
    }
    if (isset($info['timeCreated'])) {
      $jsonResponse->created = $info['timeCreated'];
    }
    if (isset($info['updated'])) {
      $jsonResponse->lastModified = $info['updated'];
    }
    if (isset($info['contentType'])) {
      $jsonResponse->contentType = $info['contentType'];
    }
    if (isset($info['contentEncoding'])) {
      $jsonResponse->contentEncoding = $info['contentEncoding'];
    }
    if (isset($info['etag'])) {
      $jsonResponse->etag = $info['etag'];
    }
    if (isset($info['storageClass'])) {
      $jsonResponse->storageClass = $info['storageClass'];
    }
    if (isset($info['selfLink'])) {
      $jsonResponse->selfLink = $info['selfLink'];
    }
    if (isset($info['mediaLink'])) {
      $jsonResponse->mediaLink = $info['mediaLink'];
    }
    return $jsonResponse;
  }

  function uploadObject($client, $bucketName, $objectName, $objectValue)
  {
    $bucket = $client->bucket($bucketName);
    $object = $bucket->upload($objectValue, [
      'name' => $objectName
    ]);
    $info = $object->info();

    $jsonResponse = json_decode('{}');

    if (isset($info['etag'])) {
      $jsonResponse->etag = $info['etag'];
    }

    if (isset($info['md5Hash'])) {
      $jsonResponse->md5Hash = $info['md5Hash'];
    }

    return $jsonResponse;
  }

  function endsWith($haystack, $needle)
  {
    return $needle === "" || (substr($haystack, -strlen($needle)) === $needle);
  }

  function downloadObject($client, $bucketName, $objectName, $isForStats, $timeToFirstByte = false)
  {
    $bucket = $client->bucket($bucketName);
    $object = $bucket->object($objectName);

    $info = $object->info();

    $contentEncoding = isset($info['contentEncoding']) ? $info['contentEncoding'] : "";
    $isObjectCompressed =
      $contentEncoding == "gzip" || $this->endsWith($objectName, ".gz");

    $stream = $object->downloadAsStream();
    $contents = $stream->getContents();

    $hash = $isObjectCompressed ?
      md5(gzdecode($contents)) : md5($contents);

    $jsonResponse = json_decode('{}');
    $jsonResponse->md5 = $hash;
    if ($isForStats) {
      if (isset($info['size'])) {
        $jsonResponse->contentLength = $info['size'];
      }
      if ($isObjectCompressed) {
        $jsonResponse->isObjectCompressed = true;
      }
    }

    return $jsonResponse;
  }

  function deleteObject($client, $bucketName, $objectName)
  {
    $bucket = $client->bucket($bucketName);
    $object = $bucket->object($objectName);
    $object->delete();

    $jsonResponse = json_decode('{}');
    $jsonResponse->deleted = true;
    return $jsonResponse;
  }
}
