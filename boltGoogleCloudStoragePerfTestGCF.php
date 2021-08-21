<?php

include_once "boltGoogleCloudStorageOpsClient.php";

use Psr\Http\Message\ServerRequestInterface;

// Here
function boltGcsPerfTestGcfEntry(ServerRequestInterface $request): void
{
  $body = $request->getBody()->getContents();
  $event = !empty($body) ? json_decode($body, true) : $request->getQueryParams();
  if (!$event) {
    parse_str($body, $event);
  }

  $opsClient = new BoltGoogleCloudStorageOpsClient();

  $getPerfStats = function ($requestType) use ($event, $opsClient) {
    $maxKeys = isset($event["maxKeys"])
      ? ((int)$event["maxKeys"] <= 1000
        ? (int)$event["maxKeys"]
        : 1000)
      : 1000;

    $generateRandomValue = function () use ($event) {
      $randomValues = array();
      $maxChars = isset($event["maxObjLength"]) ? $event["maxObjLength"] : 100;
      for ($i = 0; $i <= $maxChars; $i++) {
        $randomValues[] = chr(rand(0, (122 - 48)) + 48);
      }
      return join($randomValues);
    };

    $keys =
      $requestType === RequestType::ListObjects
      ?  array_fill(0, 100, "dummy key") // For ListObjectsV2, fetching objects process is only repeated for 10 times
      : (in_array($requestType, [RequestType::UploadObject, RequestType::DeleteObject])
        ? array_map(function ($i) {
          return "bolt-gcs-perf-" . $i;
        }, range(1, $maxKeys)) // Auto generating keys for PUT or DELETE related performace tests
        : array_slice($opsClient->processEvent(array_merge($event, [
          "requestType" => RequestType::ListObjects,
          "sdkType" => SdkTypes::GCS
        ]))->objects ?? [], $maxKeys)); // Fetch keys from buckets (GoogleCloudStorage/Bolt) for GET related performace tests

    // Run performance stats for given sdkType either GoogleCloudStorage or Bolt
    $runFor = function ($sdkType) use ($event, $keys, $opsClient, $requestType, $generateRandomValue) {
      $times = [];
      $throughputs = [];
      $objectSizes = [];
      $compressedObjectsCount = 0;
      $unCompressedObjectsCount = 0;
      foreach ($keys as $key) {
        $starttime = microtime(true);
        $response = $opsClient->processEvent(array_merge($event, [
          "requestType" => $requestType,
          "sdkType" => $sdkType,
          "isForStats" => true,
          "key" => $key,
          "value" => $generateRandomValue()
        ]));
        $endtime = microtime(true);
        $perfTime = $endtime - $starttime;
        $times[] = $perfTime;
        if ($requestType === RequestType::ListObjects) {
          $throughputs[] = count($response->objects) / $perfTime;
        } else if (
          in_array($requestType, [
            RequestType::GetObject,
            RequestType::GetObjectTTFB,
            RequestType::GetObjectPassthrough,
            RequestType::GetObjectPassthroughTTFB,
          ])
        ) {
          if (isset($response->isObjectCompressed)) {
            $compressedObjectsCount++;
          } else {
            $unCompressedObjectsCount++;
          }
          $objectSizes[] = $response->contentLength;
        }
      }

      $perfStats = computePerfStats($times, $throughputs, $objectSizes);
      if ($compressedObjectsCount || $unCompressedObjectsCount) {
        $perfStats->compressedObjectsCount = $compressedObjectsCount;
        $perfStats->unCompressedObjectsCount = $unCompressedObjectsCount;
      }

      return $perfStats;
    };

    $gcsPerfStats = $runFor(SdkTypes::GCS);
    $boltPerfStats = $runFor(SdkTypes::Bolt);
    info_log("Performance statistics of ${requestType} just got completed.");

    $gcsAndBoltPerfStats = json_decode("{}");
    $gcsAndBoltPerfStats->gcsPerfStats = $gcsPerfStats;
    $gcsAndBoltPerfStats->boltPerfStats = $boltPerfStats;

    return $gcsAndBoltPerfStats;
  };

  if (isset($event["sdkType"])) {
    $event["sdkType"] =  strtoupper($event["sdkType"]);
  }
  if (isset($event["requestType"])) {
    $event["requestType"] =  strtoupper($event["requestType"]);
  }
  info_log("PerfTest -> event: " . json_encode($event, JSON_PRETTY_PRINT) . PHP_EOL);

  $perfStats = json_decode("{}");
  if ($event["requestType"] !== RequestType::All) {
    $perfStats = $getPerfStats($event["requestType"]);
  } else {
    $perfStats->{RequestType::UploadObject} = $getPerfStats(
      RequestType::UploadObject
    );
    $perfStats->{RequestType::DeleteObject} = $getPerfStats(
      RequestType::DeleteObject
    );

    $perfStats->{RequestType::ListObjects} = $getPerfStats(
      RequestType::ListObjects
    );

    $perfStats->{RequestType::GetObject} = $getPerfStats(
      RequestType::GetObject
    );
  }

  printf(json_encode($perfStats, JSON_PRETTY_PRINT) . PHP_EOL);
}

/**
 * @param opTimes array of latencies
 * @param tpTimes array of throughputs
 * @param objSizes array of object sizes
 * @returns performance statistics (latency, throughput, object size)
 */
function computePerfStats(
  $opTimes,
  $tpTimes = [],
  $objSizes = []
) {
  $fnStats = function ($_times, $_fixedPositions, $_measurement) {
    $fnAverage = function ($arr) {
      $arr = array_filter($arr);
      return  array_sum($arr) / count($arr);
    };

    if (count($_times) === 0) {
      return json_decode("{}");
    }

    $stats = json_decode("{}");
    sort($_times);
    $stats->average = number_format($fnAverage($_times), $_fixedPositions) . " " . $_measurement;
    $stats->p50 = number_format($_times[floor(count($_times) / 2)], $_fixedPositions)  . " " .  $_measurement;
    $stats->p90 = number_format($_times[floor((count($_times) - 1) * 0.9)], $_fixedPositions)  . " " .  $_measurement;

    return $stats;
  };

  $jsonResponse = json_decode('{}');

  $jsonResponse->latency = $fnStats($opTimes, 2, "ms");

  $jsonResponse->throughput = count($tpTimes) > 0 || count($opTimes) === 0
    ? $fnStats($tpTimes, 5, "objects/ms")
    : number_format(count($opTimes) / array_sum($opTimes), 5) .  " objects/ms";

  if (count($objSizes) > 0) {
    $jsonResponse->objectSize = $fnStats($objSizes, 2, "bytes");
  }

  return $jsonResponse;
}
