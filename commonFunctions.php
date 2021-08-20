<?php

function info_log($logInfo)
{
  error_log($logInfo);
}

function getBoltRegion()
{
  $curl = curl_init();
  $headers = ['Metadata-Flavor: Google'];
  curl_setopt($curl, CURLOPT_URL, "http://metadata.google.internal/computeMetadata/v1/instance/zone");
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  $data = curl_exec($curl);
  curl_close($curl);

  $parts = explode("/", $data);
  $zone = end($parts);
  $region = strpos($zone, '-')
    ? substr($zone, 0, strrpos($zone, '-'))
    : $zone;

  return $region;
}

function getBoltURL()
{
  return str_replace("{region}", getBoltRegion(), $_ENV["BOLT_URL"]);
}
