<?php
$host = 'localhost';
$dbname = 'u330102446_meshscan';
$user = 'u330102446_meshscanuser';
$pass = 'Mindfreakz123@@@@';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (!defined('ONESIGNAL_APP_ID')) define('ONESIGNAL_APP_ID', 'a0b31e81-19b4-44bd-b4c9-843bcc1e673c');
if (!defined('ONESIGNAL_REST_API_KEY')) define('ONESIGNAL_REST_API_KEY', 'os_v2_app_uczr5aizwrcl3ngjqq54yhthhqdsj2vca32e3rnzrjgkxv6dhi6o2syjbsxkimzydtl4mn7x5tc5cvqbz4u64fb6q2vra7ehvgsslbq');
if (!defined('APP_PUBLIC_URL'))     define('APP_PUBLIC_URL',     'https://meshibon.app');

if (!defined('ORG_EMAIL_DOMAIN')) define('ORG_EMAIL_DOMAIN', 'splacebpo.com');
if (!defined('MAIL_FROM'))       define('MAIL_FROM', 'no-reply@splacebpo.com');
if (!defined('MAIL_FROM_NAME'))  define('MAIL_FROM_NAME', 'Meshibon Portal');

/**
 * Optional helper:
 * - If given "name" (no '@'), returns "name@ORG_EMAIL_DOMAIN".
 * - If given an email, returns the same email normalized (lowercase) only if it matches the org domain; otherwise null.
 * - If empty, returns null.
 */
if (!function_exists('canonical_org_email')) {
  function canonical_org_email(string $input): ?string {
    $v = trim(strtolower($input));
    if ($v === '') return null;
    if (strpos($v, '@') === false) {
      return $v . '@' . ORG_EMAIL_DOMAIN;
    }
    [$local, $domain] = explode('@', $v, 2);
    if ($domain !== ORG_EMAIL_DOMAIN) return null;
    return $local . '@' . ORG_EMAIL_DOMAIN;
  }
}
