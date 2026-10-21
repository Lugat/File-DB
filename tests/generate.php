<?php
declare(strict_types=1);

/**
 * Generates large, deterministic test data in tests/data/.
 *   php tests/generate.php [userCount=50000] [ordersPerUser=3]
 */

$userCount = (int)($argv[1] ?? 50000);
$ordersPerUser = (int)($argv[2] ?? 3);
$dir = __DIR__.'/data';
if (!is_dir($dir)) {
  mkdir($dir, 0777, true);
}

mt_srand(42);
$firstNames = ['Emily', 'Michael', 'Sophia', 'James', 'Olivia', 'Lukas', 'Anna', 'Jonas', 'Marie', 'Noah', 'Lea', 'Ben'];
$lastNames  = ['Johnson', 'Smith', 'Müller', 'Schmidt', 'Brown', 'Weber', 'Garcia', 'Fischer', 'Miller', 'Wagner'];
$countries  = ['United States', 'Germany', 'France', 'Austria', 'Switzerland', 'Spain'];
$cities     = ['Berlin', 'New York', 'Paris', 'Wien', 'Zürich', 'Madrid', 'Hamburg', 'Boston'];
$companies  = ['Acme', 'Smith and Sons', 'Globex', 'Initech', 'Umbrella', "O'Reilly"];
$statuses   = ['active', 'inactive', 'pending'];
$tags       = ['vip', 'beta', 'newsletter', 'b2b', 'b2c'];
$pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];

$users = [];
for ($i = 1; $i <= $userCount; $i++) {
  $first = $pick($firstNames);
  $last = $pick($lastNames);
  $user = [
    'id'        => $i,
    'firstName' => $first,
    'lastName'  => $last,
    'email'     => strtolower($first.'.'.$last.$i).'@example.com',
    'age'       => mt_rand(18, 80),
    'status'    => $pick($statuses),
    'verified'  => (bool)mt_rand(0, 1),
    'score'     => mt_rand(0, 10000) / 100,
    'tags'      => array_values(array_unique([$pick($tags), $pick($tags)])),
    'createdAt' => date('Y-m-d', 1577836800 + mt_rand(0, 5 * 365) * 86400),
    'deletedAt' => mt_rand(0, 9) ? null : date('Y-m-d', 1704067200 + mt_rand(0, 365) * 86400),
    'company'   => [
      'name'    => $pick($companies),
      'address' => ['country' => $pick($countries), 'city' => $pick($cities)],
    ],
  ];
  // Some records lack an optional field, to test "missing" vs. "null"
  if (0 === $i % 7) {
    unset($user['score']);
  }
  $users[] = $user;
}

$orders = [];
$orderId = 1;
foreach ($users as $user) {
  $n = mt_rand(0, $ordersPerUser * 2);
  for ($j = 0; $j < $n; $j++) {
    $orders[] = [
      'order_id' => $orderId++,
      'user_id'  => $user['id'],
      'amount'   => mt_rand(100, 100000) / 100,
      'status'   => $pick(['paid', 'open', 'refunded']),
    ];
  }
}

foreach (['users' => $users, 'orders' => $orders] as $name => $rows) {
  $file = "{$dir}/{$name}.json";
  file_put_contents($file, json_encode($rows, JSON_UNESCAPED_UNICODE));
  printf("%-8s %8d records  %6.1f MB  %s\n", $name, count($rows), filesize($file) / 1048576, $file);
}
