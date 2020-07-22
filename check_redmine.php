#!/usr/bin/php
<?php

/**
 * Copyright 2020 Megaphone Technology Consulting LLC
 * Released under the Affero GNU Public License version 3
 * but with NO WARRANTY: neither the implied warranty of merchantability
 * nor fitness for a particular purpose
 *
 * Place in /usr/lib/nagios/plugins
 *
 * Call with the command:
 * /usr/bin/php /usr/lib/nagios/plugins/check_redmine.php
 *
 * Required arguments:
 * --url <hostname> (e.g. https://redmine.example.com)
 * --api-key <an API key with admin permissions>
 *
 * Optional arguments:
 * --check-date Check contacts created after this date in ISO 8601 (yyyy-mm-dd) format.  If you don't specify a check date, it will assume you want users created in the last 2 days.
 */
$shortopts = '';
$longopts = ['api-key:', 'url:', 'check-date::'];
$options = getopt($shortopts, $longopts);
checkRequired($options);

$apiKey = $options['api-key'];
$url = $options['url'];
// $checkDate should default to 3 days ago if not otherwise specified.
$dateObj = new DateTime();
$dateObj->sub(new DateInterval('P2D'));
$checkDate = $options['check-date'] ?? $dateObj->format('Y-m-d');

usersWithoutProjectsCheck($apiKey, $url, $checkDate);

/**
 * Given an array of command-line options, do some sanity checks, bail if missing required fields etc.
 * @param array $options
 */
function checkRequired($options) {
  $requiredArguments = ['url', 'api-key'];
  $arguments = array_keys($options);
  $missing = NULL;
  foreach ($requiredArguments as $required) {
    if (!in_array($required, $arguments)) {
      $missing .= " $required";
    }
  }
  if (isset($missing)) {
    echo "You are missing the following required arguments:$missing";
    exit(3);
  }
}

/**
 * Return an array of all Redmine users.
 */
function usersWithoutProjectsCheck($apiKey, $url, $checkDate) {
  $limit = 100;
  $options = array(
    'http' => array(
      'header'  => "Content-type: application/x-www-form-urlencoded\r\nUser-Agent: Megaphone Redmine check\r\n",
    ),
  );
  $context  = stream_context_create($options);
  // Gather a list of new users.
  $newUsers = [];
  $usersChecked = $totalUsers = 0;
  while ($usersChecked <= $totalUsers) {
    $query = "$url/users.json?key=$apiKey&limit=$limit&offset=$usersChecked&status=1";
    $result = file_get_contents($query, FALSE, $context);
    $userList = json_decode($result, TRUE);
    $totalUsers = $userList['total_count'];
    $usersChecked += $limit;
    foreach ($userList['users'] as $user) {
      if (strcmp($user['created_on'], $checkDate) >= 0) {
        $newUsers[] = $user;
      }
    }
  }

  // Do an API call for each user to check their groups.  Add their name to $usersWithoutProjects if they don't have any projects.
  $usersWithoutProjects = '';
  foreach ($newUsers as $newUser) {
    $uid = $newUser['id'];
    $query = "$url/users/$uid.json?key=$apiKey&include=memberships";
    $result = file_get_contents($query, FALSE, $context);
    $userDetails = json_decode($result, TRUE)['user'];
    if (!($userDetails['memberships'] ?? FALSE)) {
      $usersWithoutProjects .= $userDetails['firstname'] . ' ' . $userDetails['lastname'] . ' (' . $userDetails['mail'] . ")\n";
    }
  }

  if ($usersWithoutProjects) {
    echo $usersWithoutProjects;
    exit(1);
  }
  else {
    exit(0);
  }

  // Don't think we can reach this at present, but we should think about that.
  echo 'Unknown error';
  exit(3);
}
