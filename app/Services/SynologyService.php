<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SynologyService
{
    protected $client;
    protected $baseUrl;
    protected $accountId;
    protected $password;

    public function __construct()
    {
        $this->client = new Client([
            'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36']
        ]);
        $this->baseUrl   = 'https://10-0-0-2.s454666.direct.quickconnect.to:5001';  // 修正為你的 NAS 名稱
        $this->accountId = 's454666';                          // 使用您的帳號
        $this->password  = 'i06180318';                        // 使用您的密碼
    }

    public function loginAndGetSid()
    {
        try {
            $response = $this->client->post($this->baseUrl . '/webapi/auth.cgi', [
                'form_params' => [
                    'api'       => 'SYNO.API.Auth',
                    'version'   => 7, // 根據你的 DSM 版本進行修改
                    'method'    => 'login',
                    'account'   => $this->accountId,
                    'passwd'    => $this->password,
                    'session'   => 'FileStation',
                    'format'    => 'cookie'
                ]
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if ($data && $data['success']) {
                return $data['data']['sid'];
            }
        } catch (GuzzleException $e) {
            dd($response->getHeaders(), $response->getBody()->getContents());
        }
        return null;
    }

    public function getSharedFolders($sid)
    {
        try {
            $response = $this->client->get($this->baseUrl . '/webapi/entry.cgi', [
                'query' => [
                    'api'     => 'SYNO.FileStation.List',
                    'version' => 7,
                    'method'  => 'list_share',
                    '_sid'    => $sid
                ]
            ]);
            dd($response->getBody()->getContents());
            $data = json_decode($response->getBody()->getContents(), true);
            if ($data && $data['success']) {
                return $data['data']['shares'];
            }
        }
        catch (GuzzleException $e) {
            return [];  // 在實際應用中，也可能需要進行錯誤記錄或其他處理
        }
        return [];
    }
}
