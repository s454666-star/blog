<?php

namespace App\Console\Commands;

use App\Models\TestVi;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use killworm737\qrcode\Qrcode;

class TestCommand extends Command
{
    protected $signature   = 'command:test';
    protected $description = 'Command description';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $qrcodeClass = new Qrcode();

        $aesKey = "72FA211F3859235E14EB7AB09AFA496F8A36AD692A14369D0DB71E900437AFD3";// input your aeskey
        $invoiceNumAndRandomCode = "NN427641010410";// input your invoiceNumber And RandomCode
        $a = $qrcodeClass->aes128_cbc_encrypt($aesKey, $invoiceNumAndRandomCode);
        dd($a);
    }
}
