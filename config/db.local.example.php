<?php
/**
 * Local database credentials for this server.
 *
 * Copy this file to "db.local.php" (same folder) and fill in the values from
 * hPanel > Databases > Management. "db.local.php" is git-ignored, so the real
 * password never ends up in the GitHub repository.
 *
 * The file must return an array with these keys:
 *   host, username, password, name
 */
return [
    "host" => "localhost",
    "username" => "YOUR_DATABASE_USER",
    "password" => "YOUR_DATABASE_PASSWORD",
    "name" => "YOUR_DATABASE_NAME",
];
