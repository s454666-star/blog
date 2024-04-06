<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

class GetDataController extends Controller
{
    public function fetchData()
    {
        $client = new Client();
        $urls   = []; // Initialize an array to hold the extracted URLs

        for ($i = 1; $i <= 25; $i++) {
            $url = "https://2a5n.com/f/3/new/{$i}.html";

            try {
                $headers  = [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3'
                ];
                $response = $client->request('GET', $url, [ 'verify' => false, 'headers' => $headers ]);
                // Check if the response is successful
                if ($response->getStatusCode() == 200) {
                    $body    = $response->getBody()->getContents();
                    $crawler = new Crawler($body);
                    // Filter the content to get all 'a' tags and extract their 'href' attributes
                    $crawler->filter('a')->each(function (Crawler $node) use (&$urls) {
                        $href = $node->attr('href');
                        // Use regular expression to extract the numeric part
                        if (preg_match('/https:\/\/2a5n.com\/t\/(\d+).html/', $href, $matches)) {
                            $numericPart = (int)$matches[1]; // Convert the string to an integer
                            // Check if the numeric part is >= 100000
                            if ($numericPart >= 1000000) {
                                $urls[]           = $href;
                                $detailController = new UrlDetailController();
                                $detailController->fetchDetails($href);
                            }
                        }
                    });
                }
            }
            catch (GuzzleException $e) {
                // Dump the error message and stop script execution
                dd('HTTP Request failed: ' . $e->getMessage());
            }
            catch (\Exception $e) {
                // Catch any other general exceptions
                dd('An error occurred: ' . $e->getMessage());
            }
        }

        // Use dd() to dump the array of URLs for debugging
        dd('success');
    }
}
