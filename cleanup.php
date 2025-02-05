<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required(['POCKET_CONSUMER_KEY']);

$consumerKey = $_ENV['POCKET_CONSUMER_KEY'];

$client = new Client([
    'base_uri' => 'https://getpocket.com/v3/',
    'headers' => [
        'Content-Type' => 'application/json; charset=UTF-8',
        'X-Accept' => 'application/json',
    ],
]);

/**
 * Login process
 */

if (is_readable(__DIR__ . '/.access_token')) {
    $accessToken = file_get_contents(__DIR__ . '/.access_token');
    goto loggedIn;
}

$response = $client->post('https://getpocket.com/v3/oauth/request', [
    'json' => [
        'consumer_key' => $consumerKey,
        'redirect_uri' => 'http://localhost:8080/callback.php',
        'state' => 'cleanup-' . random_int(100000, 999999),
    ],
]);

$code = json_decode($response->getBody()->getContents())->code;

echo "Open this URL in your browser and authorize the app:\n";
echo "https://getpocket.com/auth/authorize?request_token=$code&redirect_uri=http://localhost:8000/callback.php\n";

echo "Press Enter when you're done...";
fgets(STDIN);

try {
    $response = $client->post('https://getpocket.com/v3/oauth/authorize', [
        'json' => [
            'consumer_key' => $consumerKey,
            'code' => $code,
        ],
    ]);
} catch (ServerException|ClientException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $response = $e->getResponse();
    echo $response->getStatusCode() . "\n";
    echo $response->getBody()->getContents() . "\n";
    exit(1);
}
$data = json_decode($response->getBody()->getContents());
$accessToken = $data->access_token;
file_put_contents(__DIR__ . '/.access_token', $accessToken);

loggedIn:

/**
 * Get all items
 */

echo "Logged in\n";

$itemsPerRequest = 30;
$get = function ($offset) use ($client, $consumerKey, $accessToken, $itemsPerRequest) {
    try {
        return json_decode($client->post('https://getpocket.com/v3/get', [
            'json' => [
                'consumer_key' => $consumerKey,
                'access_token' => $accessToken,
                'state' => 'unread',
                'detailType' => 'simple',
                'sort' => 'oldest',
                'count' => $itemsPerRequest,
                'offset' => $offset,
                'total' => 'true',
            ],
        ])->getBody()->getContents());
    } catch (ServerException|ClientException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        $response = $e->getResponse();
        echo $response->getStatusCode() . "\n";
        echo $response->getBody()->getContents() . "\n";
        exit(2);
    }
};

$offset = 0;
$max = 100;
$items = [];
$total = null;
do {
    $data = $get($offset);
    $total = (int) $data->total;
    $list = (array) $data->list;
    $items = array_merge($items, $list);
    $offset += count($list);
    echo "Fetched $offset oldest items...";
    if (count($items) > $max) {
        echo "done\n";
        break;
    } else {
        echo "continuing\n";
    }
} while (true);

if (count($items) > $max) {
    $items = array_slice($items, 0, $max);
    echo "Trimmed to $max items\n";
}

echo "Total items in Pocket: $total" . PHP_EOL . PHP_EOL;

foreach ($items as $id => $item) {
    echo "$id: \e]8;;$item->resolved_url\e\\$item->resolved_title\e]8;;\e\\" . PHP_EOL;

}

echo "Archive these items? [y/N] ";
if (trim(fgets(STDIN)) !== 'y') {
    echo "Not archiving\n";
    exit(0);
}

/**
 * Delete all items
 */
$actions = array_map(fn ($item) => ['action' => 'archive', 'item_id' => (int) $item->item_id], $items);
try {
    $response = $client->post('https://getpocket.com/v3/send', [
        'json' => [
            'consumer_key' => $consumerKey,
            'access_token' => $accessToken,
            'actions' => $actions,
        ],
    ]);

    $results = json_decode($response->getBody()->getContents())->action_results;
    $failures = array_filter($results, fn ($result) => $result === false);
    if (count($failures) > 0) {
        echo "Archived " . (count($results) - count($failures)) . " items\n";
        echo "Failed to archive " . count($failures) . " items\n";
    } else {
        echo "Archived " . count($results) .  " items\n";
    }
} catch (ServerException|ClientException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $response = $e->getResponse();
    echo $response->getStatusCode() . "\n";
    echo $response->getBody()->getContents() . "\n";
    exit(3);
}
