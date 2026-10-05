<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fixed (hard-coded) order-loss risk percentages. Higher = easier to be displaced by peers.
// Editorial estimates from product substitutability, customer concentration and 2026-27 demand tightness.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_order_loss_risks', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_code', 12)->unique();
            $table->string('stock_name')->nullable();
            $table->unsignedTinyInteger('risk_percent');
            $table->text('basis');
            $table->string('source_url', 500)->nullable();
            $table->date('assessed_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach (array (
  0 => 
  array (
    'stock_code' => '3481',
    'stock_name' => '群創',
    'risk_percent' => 60,
    'basis' => '主力顯示面板規格可比較，客戶較易配置替代供應商；特殊顯示仍有差異化；2026–27 需求與替代性：面板供給過剩、報價承壓，需求無供不應求支撐',
    'source_url' => 'https://www.innolux.com/Uploads/22/2023Annual%20Report_5397ad.pdf',
    'assessed_at' => '2026-10-04',
  ),
  1 => 
  array (
    'stock_code' => '2408',
    'stock_name' => '南亞科',
    'risk_percent' => 40,
    'basis' => '標準DRAM介面與規格通用性高，品質有門檻但可驗證其他供應商；2026–27 需求與替代性：2026–27 DRAM 需求強、供給偏緊，降低短期被取代壓力',
    'source_url' => 'https://www.nanya.com/en/Product/',
    'assessed_at' => '2026-10-04',
  ),
  2 => 
  array (
    'stock_code' => '2344',
    'stock_name' => '華邦電',
    'risk_percent' => 30,
    'basis' => '利基記憶體與長供貨週期增加黏著度，標準介面仍有替代空間；2026–27 需求與替代性：利基型記憶體需求回升、供給偏緊',
    'source_url' => 'https://www.winbond.com/hq/application/wplp/?__locale=en_TW',
    'assessed_at' => '2026-10-04',
  ),
  3 => 
  array (
    'stock_code' => '1303',
    'stock_name' => '南亞',
    'risk_percent' => 55,
    'basis' => '塑膠纖維與大宗化學材料同規格替代性高，高階電子材料驗證降低部分風險；2026–27 需求與替代性：石化產品同質化高，需求未見供不應求',
    'source_url' => 'https://www.npc.com.tw/j2npc/zhtw/appcate/all',
    'assessed_at' => '2026-10-04',
  ),
  4 => 
  array (
    'stock_code' => '8299',
    'stock_name' => '群聯',
    'risk_percent' => 14,
    'basis' => '控制器、韌體與NAND整合客製設計提高轉換及重新驗證成本；2026–27 需求與替代性：NAND 供不應求，客製控制器客戶黏著度高',
    'source_url' => 'https://www.phison.com/imagin-plus-platform-customized-nand-flash-storage-solutions-and-asic-design-services/',
    'assessed_at' => '2026-10-04',
  ),
  5 => 
  array (
    'stock_code' => '8046',
    'stock_name' => '南電',
    'risk_percent' => 17,
    'basis' => '高階ABF細線路、多層堆疊與封裝驗證形成轉換門檻；2026–27 需求與替代性：ABF 載板 AI 需求強、高階產能偏緊',
    'source_url' => 'https://www.nanyapcb.com.tw/nypcb/chinese/Technology/ABFSRoadmap',
    'assessed_at' => '2026-10-04',
  ),
  6 => 
  array (
    'stock_code' => '6505',
    'stock_name' => '台塑化',
    'risk_percent' => 65,
    'basis' => '油品石化基本原料較標準化，客戶可比價換源；集團物流提供部分黏著度；2026–27 需求與替代性：油品與石化商品化程度高，價格競爭為主',
    'source_url' => 'https://www.fpcc.com.tw/uploads/images/ir/%E5%8F%B0%E5%A1%91%E7%9F%B3%E5%8C%96113%E5%B9%B4%E8%82%A1%E6%9D%B1%E6%9C%83%E5%B9%B4%E5%A0%B1.pdf',
    'assessed_at' => '2026-10-04',
  ),
  7 => 
  array (
    'stock_code' => '3037',
    'stock_name' => '欣興',
    'risk_percent' => 16,
    'basis' => 'IC載板及高密度互連需匹配晶片封裝設計，可靠度驗證增加轉單成本；2026–27 需求與替代性：高階載板與 PCB 需求強、產能偏緊',
    'source_url' => 'https://www.uniflex.com.tw/esg/en/product01.html',
    'assessed_at' => '2026-10-04',
  ),
  8 => 
  array (
    'stock_code' => '3081',
    'stock_name' => '聯亞',
    'risk_percent' => 18,
    'basis' => '光通訊磊晶成長與結構控制複雜，客製開發有黏著度，仍有專業廠替代；2026–27 需求與替代性：光通訊磊晶需求強、供給偏緊，客戶認證綁定',
    'source_url' => 'https://www.lmoc.com.tw/index.php?i=1&id=390&lang=cht&option=module&task=dfile',
    'assessed_at' => '2026-10-04',
  ),
  9 => 
  array (
    'stock_code' => '3189',
    'stock_name' => '景碩',
    'risk_percent' => 24,
    'basis' => 'FC-BGA、SiP載板精密製程及封裝可靠度驗證提高轉換成本；2026–27 需求與替代性：載板需求回升但客戶集中，競爭者較多',
    'source_url' => 'https://www.kinsus.com.tw/en',
    'assessed_at' => '2026-10-04',
  ),
  10 => 
  array (
    'stock_code' => '3376',
    'stock_name' => '新日興',
    'risk_percent' => 38,
    'basis' => '客製樞紐精密製造與專利有門檻，新機種仍可重新選擇合格供應商；2026–27 需求與替代性：產品線多元但同業可替代，需求中性',
    'source_url' => 'https://www.szs-group.com/page/about-company',
    'assessed_at' => '2026-10-04',
  ),
  11 => 
  array (
    'stock_code' => '6213',
    'stock_name' => '聯茂',
    'risk_percent' => 24,
    'basis' => '高頻高速及車用板材配方、電性與可靠度驗證提高導入後轉單成本；2026–27 需求與替代性：高速 CCL 需求強，但同業（台光電、台燿）可分單',
    'source_url' => 'https://www.iteqcorp.com/',
    'assessed_at' => '2026-10-04',
  ),
  12 => 
  array (
    'stock_code' => '6643',
    'stock_name' => 'M31',
    'risk_percent' => 22,
    'basis' => '矽智財需完成製程與晶片設計驗證，嵌入客戶SoC後更換需重新整合驗證；新設計案仍有其他IP供應商競爭；2026–27 需求與替代性：IP 新案仍有競爭者；2026 仍虧損',
    'source_url' => 'https://www.m31tech.com/qa/',
    'assessed_at' => '2026-10-04',
  ),
  13 => 
  array (
    'stock_code' => '6274',
    'stock_name' => '台燿',
    'risk_percent' => 20,
    'basis' => '低損耗材料匹配訊號完整性及板廠製程，認證可靠度要求提高轉換成本；2026–27 需求與替代性：高速 CCL AI 伺服器需求強',
    'source_url' => 'https://www.tuc.com.tw/en-us/products2',
    'assessed_at' => '2026-10-04',
  ),
  14 => 
  array (
    'stock_code' => '8021',
    'stock_name' => '尖點',
    'risk_percent' => 38,
    'basis' => '精密微型鑽針與機械、雷射鑽孔整合服務形成品質和製程配合門檻，但耗材及加工服務仍可配置其他合格供應商；2026–27 需求與替代性：PCB 擴產帶動鑽針需求，但耗材可配置其他供應商',
    'source_url' => 'https://www.topoint.tw/tw/about',
    'assessed_at' => '2026-10-04',
  ),
  15 => 
  array (
    'stock_code' => '2383',
    'stock_name' => '台光電',
    'risk_percent' => 14,
    'basis' => '高階低損耗材料與專利配方有差異化，替換需重新驗證電性製程；2026–27 需求與替代性：高階 CCL 為 AI 伺服器關鍵材料，供不應求',
    'source_url' => 'https://www.emctw.com/zh-TW/about_milestone/index',
    'assessed_at' => '2026-10-04',
  ),
  16 => 
  array (
    'stock_code' => '3167',
    'stock_name' => '大量',
    'risk_percent' => 36,
    'basis' => '精密設備、自研控制器與售後服務有門檻，新設備採購仍可比較其他供應商；2026–27 需求與替代性：設備客戶集中，需求強但需持續爭取新案',
    'source_url' => 'https://www.taliang.com/',
    'assessed_at' => '2026-10-04',
  ),
  17 => 
  array (
    'stock_code' => '3491',
    'stock_name' => '昇達科',
    'risk_percent' => 25,
    'basis' => '微波毫米波元件客製設計，精密製造及低損耗性能提高替換成本；2026–27 需求與替代性：衛星／微波元件需求成長，客戶集中',
    'source_url' => 'https://www.umt-tw.com/tw/index.php',
    'assessed_at' => '2026-10-04',
  ),
  18 => 
  array (
    'stock_code' => '3443',
    'stock_name' => '創意',
    'risk_percent' => 14,
    'basis' => '客製ASIC整合IP、實體設計與先進封裝，專案轉移需重新設計驗證；2026–27 需求與替代性：ASIC 設計服務與客戶專案綁定，需求強',
    'source_url' => 'https://www.guc-asic.com/upload/2026_04_16/8_202604161551145dnuwxrHk5.pdf',
    'assessed_at' => '2026-10-04',
  ),
  19 => 
  array (
    'stock_code' => '6515',
    'stock_name' => '穎崴',
    'risk_percent' => 18,
    'basis' => '測試介面溫控方案客製匹配晶片及測試條件，重新導入需驗證；2026–27 需求與替代性：測試座需求強、客戶認證門檻高',
    'source_url' => 'https://www.winwayglobal.com/zh-TW',
    'assessed_at' => '2026-10-04',
  ),
  20 => 
  array (
    'stock_code' => '2059',
    'stock_name' => '川湖',
    'risk_percent' => 10,
    'basis' => '伺服器滑軌專利與OEM/ODM認證、機構配合提高更換成本；2026–27 需求與替代性：AI 伺服器滑軌市占高，需求供不應求',
    'source_url' => 'https://www.kingslide.com/about_research',
    'assessed_at' => '2026-10-04',
  ),
  21 => 
  array (
    'stock_code' => '3653',
    'stock_name' => '健策',
    'risk_percent' => 17,
    'basis' => '依晶片架構與功耗共同設計散熱，精密加工與垂直整合提高替換門檻；2026–27 需求與替代性：散熱／均熱片需求強、供給偏緊',
    'source_url' => 'https://www.jentech.com.tw/zh',
    'assessed_at' => '2026-10-04',
  ),
  22 => 
  array (
    'stock_code' => '6223',
    'stock_name' => '旺矽',
    'risk_percent' => 18,
    'basis' => '探針卡整合客製載板探針與訊號完整性，重新設計驗證有門檻；2026–27 需求與替代性：探針卡需求強、認證門檻高',
    'source_url' => 'https://www.mpi.com.tw/probecard/',
    'assessed_at' => '2026-10-04',
  ),
  23 => 
  array (
    'stock_code' => '6510',
    'stock_name' => '精測',
    'risk_percent' => 20,
    'basis' => '探針卡客製訊號完整性、熱與機構設計，替換須重新驗證測試效能；2026–27 需求與替代性：測試介面需求強、客戶認證門檻高',
    'source_url' => 'https://www.chpt.com/xmdoc/cont?sid=0L158502416391397096&xsmsid=0G328551704882485281',
    'assessed_at' => '2026-10-04',
  ),
  24 => 
  array (
    'stock_code' => '2368',
    'stock_name' => '金像電',
    'risk_percent' => 18,
    'basis' => '高階伺服器板要求高層數、低損耗與訊號完整性，製程可靠度門檻高；2026–27 需求與替代性：伺服器 PCB 需求強、高階產能偏緊',
    'source_url' => 'https://www.gce.com.tw/product.html',
    'assessed_at' => '2026-10-04',
  ),
  25 => 
  array (
    'stock_code' => '4958',
    'stock_name' => '臻鼎-KY',
    'risk_percent' => 38,
    'basis' => '高階HDI製程可靠度有門檻，但多種PCB產品整體仍有同業替代空間；2026–27 需求與替代性：軟板客戶集中，同業與陸廠競爭，需求中性',
    'source_url' => 'https://www.zdtco.com/tw/product/%E9%AB%98%E5%AF%86%E5%BA%A6%E9%80%A3%E6%8E%A5%E6%9D%BF',
    'assessed_at' => '2026-10-04',
  ),
  26 => 
  array (
    'stock_code' => '2454',
    'stock_name' => '聯發科',
    'risk_percent' => 30,
    'basis' => 'SoC軟硬體平台有設計門檻，終端週期短，新機種可改採競爭平台；2026–27 需求與替代性：手機 SoC 受同業競爭；ASIC 新案為增量',
    'source_url' => 'https://www.mediatek.com/hubfs/728015/MediaTek%20Assets/Pdfs/Annual%20Reports/2024-English-Annual-Report.pdf',
    'assessed_at' => '2026-10-04',
  ),
  27 => 
  array (
    'stock_code' => '5274',
    'stock_name' => '信驊',
    'risk_percent' => 8,
    'basis' => 'BMC硬體韌體深度整合，伺服器可靠度及既有平台導入形成很高轉換門檻；2026–27 需求與替代性：BMC 近乎寡占，AI 伺服器需求強',
    'source_url' => 'https://www.aspeedtech.com/investor_faq/',
    'assessed_at' => '2026-10-04',
  ),
  28 => 
  array (
    'stock_code' => '2455',
    'stock_name' => '全新',
    'risk_percent' => 22,
    'basis' => 'MOCVD磊晶與客戶協同研發有材料製程門檻，替換須重驗結構品質；2026–27 需求與替代性：GaAs 磊晶需求成長，客戶認證綁定',
    'source_url' => 'https://www.vpec.com.tw/vpec/homeweb/about.php?level=2&menu1=M100001&menu2=M200019',
    'assessed_at' => '2026-10-04',
  ),
  29 => 
  array (
    'stock_code' => '9921',
    'stock_name' => '巨大',
    'risk_percent' => 52,
    'basis' => '品牌製造有差異化，但自行車購買少有系統綁定，易比較替代品牌；2026–27 需求與替代性：自行車需求疲弱，品牌可替代',
    'source_url' => 'https://origin.giantgroup-cycling.com/files/images/iroverview/Annual%20report/Eng/114_Annual_Report_EN.pdf',
    'assessed_at' => '2026-10-04',
  ),
  30 => 
  array (
    'stock_code' => '3017',
    'stock_name' => '奇鋐',
    'risk_percent' => 28,
    'basis' => '整機散熱有工程門檻，產品涵蓋風扇散熱件機箱，可替代性依產品而異；2026–27 需求與替代性：散熱模組需求強但同業多',
    'source_url' => 'https://www.avc.co/en-us/',
    'assessed_at' => '2026-10-04',
  ),
  31 => 
  array (
    'stock_code' => '6805',
    'stock_name' => '富世達',
    'risk_percent' => 30,
    'basis' => '轉軸非標準客製、前期共同開發精密製程專利提高替換成本；2026–27 需求與替代性：轉軸需求受手機換機循環影響，客戶集中',
    'source_url' => 'https://www.fositek.com/Portals/0/FileUpload/Shareholders/2025/2025AnnualReport.pdf',
    'assessed_at' => '2026-10-04',
  ),
  32 => 
  array (
    'stock_code' => '3711',
    'stock_name' => '日月光投控',
    'risk_percent' => 16,
    'basis' => '先進封裝SiP及測試整合牽涉設計可靠度驗證，替換門檻高於一般組裝；2026–27 需求與替代性：先進封測 CoWoS 外溢需求強',
    'source_url' => 'https://ase.aseglobal.com/system-in-package/',
    'assessed_at' => '2026-10-04',
  ),
  33 => 
  array (
    'stock_code' => '6446',
    'stock_name' => '藥華藥',
    'risk_percent' => 15,
    'basis' => 'BESREMi核准生物藥替代涉及臨床法規門檻，仍有其他療法；此處指產品療法替代；2026–27 需求與替代性：專利藥，替代性低',
    'source_url' => 'https://purplebooksearch.fda.gov/index.cfm?blaNo=761166&event=productdetails',
    'assessed_at' => '2026-10-04',
  ),
  34 => 
  array (
    'stock_code' => '2308',
    'stock_name' => '台達電',
    'risk_percent' => 20,
    'basis' => '電源散熱系統整合有設計導入門檻，整體方案較難直接替換；2026–27 需求與替代性：電源與散熱需求強，客戶長期綁定',
    'source_url' => 'https://www.deltaww.com/en-US/company/our-businesses/power-electronics',
    'assessed_at' => '2026-10-04',
  ),
  35 => 
  array (
    'stock_code' => '3036',
    'stock_name' => '文曄',
    'risk_percent' => 40,
    'basis' => '代理線技術支援與全球供應鏈有黏著度，通路仍有競爭替代空間；2026–27 需求與替代性：通路業務可被其他代理商取代',
    'source_url' => 'https://www.wtmec.com/wp-content/uploads/2026/04/WT_2025-Annual-Report_CHINESE.pdf',
    'assessed_at' => '2026-10-04',
  ),
  36 => 
  array (
    'stock_code' => '3661',
    'stock_name' => '世芯-KY',
    'risk_percent' => 30,
    'basis' => '先進ASIC與IP封裝高度客製，既有專案轉換成本高，新案仍可另選夥伴；2026–27 需求與替代性：ASIC 客戶集中，需求強',
    'source_url' => 'https://www.alchip.com/en',
    'assessed_at' => '2026-10-04',
  ),
  37 => 
  array (
    'stock_code' => '6415',
    'stock_name' => '矽力-KY',
    'risk_percent' => 38,
    'basis' => '類比電源IC有導入可靠度門檻，通用產品替代選項較多；2026–27 需求與替代性：電源類比 IC 競爭激烈',
    'source_url' => 'https://www.silergy.com/quality',
    'assessed_at' => '2026-10-04',
  ),
  38 => 
  array (
    'stock_code' => '3035',
    'stock_name' => '智原',
    'risk_percent' => 36,
    'basis' => '自有IP與客製ASIC流程整合，既有設計切換成本高，新案仍可競標；2026–27 需求與替代性：ASIC／IP 服務客戶集中，需求中性偏強',
    'source_url' => 'https://www.faraday-tech.com/en/entry/AboutFaraday',
    'assessed_at' => '2026-10-04',
  ),
  39 => 
  array (
    'stock_code' => '3665',
    'stock_name' => '貿聯-KY',
    'risk_percent' => 33,
    'basis' => '客製線束及系統整合提高切換成本，亦有標準互連件，依應用而異；2026–27 需求與替代性：線束供應需通過客戶認證，但同業可分單',
    'source_url' => 'https://www.bizlinktech.com/capabilities',
    'assessed_at' => '2026-10-04',
  ),
  40 => 
  array (
    'stock_code' => '6285',
    'stock_name' => '啟碁',
    'risk_percent' => 42,
    'basis' => '射頻天線軟硬體整合認證有門檻，仍可由合格同業重新開發；2026–27 需求與替代性：網通代工可被其他代工廠分單',
    'source_url' => 'https://www.wnc.com.tw/en/about/wnc',
    'assessed_at' => '2026-10-04',
  ),
  41 => 
  array (
    'stock_code' => '2049',
    'stock_name' => '上銀',
    'risk_percent' => 42,
    'basis' => '螺桿滑軌精度壽命有差異，標準品選擇多，替換需機構驗證；2026–27 需求與替代性：線性傳動同業與陸廠競爭，需求中性',
    'source_url' => 'https://hiwin.com/products/ballscrews-supports/',
    'assessed_at' => '2026-10-04',
  ),
  42 => 
  array (
    'stock_code' => '3324',
    'stock_name' => '雙鴻',
    'risk_percent' => 28,
    'basis' => '液冷與客製散熱提高導入門檻，仍有同業技術替代需重驗效能；2026–27 需求與替代性：AI 散熱需求強，但同業多',
    'source_url' => 'https://www.auras.com.tw/CMSFS/InformationCenters/English/ecfc7b18-d55e-4ca3-a673-b449f83d051c.pdf',
    'assessed_at' => '2026-10-04',
  ),
  43 => 
  array (
    'stock_code' => '3231',
    'stock_name' => '緯創',
    'risk_percent' => 33,
    'basis' => 'ODM設計供應鏈量產整合有門檻，仍可選其他夥伴，依產品複雜度而異；2026–27 需求與替代性：伺服器代工需求強，客戶集中',
    'source_url' => 'https://www.wistron.com/en/TechnologyLeadership',
    'assessed_at' => '2026-10-04',
  ),
  44 => 
  array (
    'stock_code' => '2345',
    'stock_name' => '智邦',
    'risk_percent' => 22,
    'basis' => '高速交換器共同設計量產驗證有門檻，開放平台仍可選其他ODM；2026–27 需求與替代性：AI 交換器需求強，客戶綁定',
    'source_url' => 'https://www.accton.com/all-in-one-services/',
    'assessed_at' => '2026-10-04',
  ),
  45 => 
  array (
    'stock_code' => '2409',
    'stock_name' => '友達',
    'risk_percent' => 58,
    'basis' => '傳統面板競爭及替代性高，車用商用客製顯示降低部分風險；2026–27 需求與替代性：面板同質化高，需求未見供不應求',
    'source_url' => 'https://www.auo.com/upload/media/ir/2025_AUO_Annual_Report_EN.pdf',
    'assessed_at' => '2026-10-04',
  ),
  46 => 
  array (
    'stock_code' => '3105',
    'stock_name' => '穩懋',
    'risk_percent' => 36,
    'basis' => '砷化鎵代工緊密配合客戶電路製程，轉廠須重做驗證及良率爬升；2026–27 需求與替代性：GaAs 代工同業（宏捷科等）可分單',
    'source_url' => 'https://www.winfoundry.com/en-US/About/about_company',
    'assessed_at' => '2026-10-04',
  ),
  47 => 
  array (
    'stock_code' => '3131',
    'stock_name' => '弘塑',
    'risk_percent' => 20,
    'basis' => '濕製程設備連動客戶製程良率，客製與現場服務提高換機驗證成本；2026–27 需求與替代性：濕製程設備客戶認證與製程綁定，需求強',
    'source_url' => 'https://www.gptc.com.tw/about/esg/',
    'assessed_at' => '2026-10-04',
  ),
  48 => 
  array (
    'stock_code' => '4551',
    'stock_name' => '智伸科',
    'risk_percent' => 30,
    'basis' => '汽車安全醫療精密零件重視加工一致性與品質認證，替換須重新驗證；2026–27 需求與替代性：精密零件客戶集中，需求中性',
    'source_url' => 'https://global.com.tw/%E9%97%9C%E6%96%BC%E6%99%BA%E4%BC%B8/',
    'assessed_at' => '2026-10-04',
  ),
  49 => 
  array (
    'stock_code' => '6488',
    'stock_name' => '環球晶',
    'risk_percent' => 38,
    'basis' => '矽晶圓純度製程一致性要求高，替代料須驗證，仍有其他大型供應商；2026–27 需求與替代性：矽晶圓同業與陸廠競爭',
    'source_url' => 'https://www.sas-globalwafers.com/en/finance/2025-2/globalwafers_2024-annual-report-en/',
    'assessed_at' => '2026-10-04',
  ),
  50 => 
  array (
    'stock_code' => '6239',
    'stock_name' => '力成',
    'risk_percent' => 32,
    'basis' => '封裝測試程式及良率認證增加切換成本，記憶體封測仍有同業替代；2026–27 需求與替代性：記憶體封測需求強，但客戶可分單',
    'source_url' => 'https://www.pti.com.tw/',
    'assessed_at' => '2026-10-04',
  ),
  51 => 
  array (
    'stock_code' => '1560',
    'stock_name' => '中砂',
    'risk_percent' => 28,
    'basis' => 'CMP 鑽石碟為晶圓廠耗材，通過製程認證後更換需重新驗證；日韓同業仍有競爭；2026–27 需求與替代性：鑽石碟耗材，需求隨先進製程增加',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  52 => 
  array (
    'stock_code' => '2303',
    'stock_name' => '聯電',
    'risk_percent' => 45,
    'basis' => '特殊製程設計套件車規驗證形成轉廠門檻，成熟製程仍有其他供應選擇；2026–27 需求與替代性：成熟製程同業與陸廠競爭',
    'source_url' => 'https://www.umc.com/en/Product/technologies/Index/specialty',
    'assessed_at' => '2026-10-04',
  ),
  53 => 
  array (
    'stock_code' => '2360',
    'stock_name' => '致茂',
    'risk_percent' => 22,
    'basis' => '精密量測、專用測試及客製自動化整合提高替換驗證成本；2026–27 需求與替代性：測試設備需求強、客戶認證綁定',
    'source_url' => 'https://www.chromaate.com/en/test_solutions/photonics_test_solution',
    'assessed_at' => '2026-10-04',
  ),
  54 => 
  array (
    'stock_code' => '8210',
    'stock_name' => '勤誠',
    'risk_percent' => 28,
    'basis' => '伺服器機殼需配合客戶設計，具設計與交期門檻，但機構件仍有其他供應商；2026–27 需求與替代性：AI 伺服器機殼需求強，但客戶可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  55 => 
  array (
    'stock_code' => '2301',
    'stock_name' => '光寶科',
    'risk_percent' => 38,
    'basis' => '高效電源安規客製伺服器設計有門檻，模組化產品仍有合格同業；2026–27 需求與替代性：電源與零組件同業競爭，需求中性偏強',
    'source_url' => 'https://www.liteon.com/en/solutions/',
    'assessed_at' => '2026-10-04',
  ),
  56 => 
  array (
    'stock_code' => '2330',
    'stock_name' => '台積電',
    'risk_percent' => 5,
    'basis' => '先進製程封裝及設計生態系高度整合，轉廠需大幅重新設計驗證；2026–27 需求與替代性：先進製程與封裝供不應求，近乎無可替代',
    'source_url' => 'https://investor.tsmc.com/static/annualReports/2025/english/index.html',
    'assessed_at' => '2026-10-04',
  ),
  57 => 
  array (
    'stock_code' => '6472',
    'stock_name' => '保瑞',
    'risk_percent' => 28,
    'basis' => '生技 CDMO 需通過法規與客戶認證，更換供應商成本高；新案仍有同業競爭；2026–27 需求與替代性：CDMO 客戶綁定，需求穩定',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  58 => 
  array (
    'stock_code' => '3044',
    'stock_name' => '健鼎',
    'risk_percent' => 36,
    'basis' => 'PCB製程品質量產驗證有門檻，整體仍面對多家板廠競爭；2026–27 需求與替代性：PCB 同業多，需求中性',
    'source_url' => 'https://www.tripod-tech.com/main/2',
    'assessed_at' => '2026-10-04',
  ),
  59 => 
  array (
    'stock_code' => '2376',
    'stock_name' => '技嘉',
    'risk_percent' => 35,
    'basis' => '伺服器散熱整合驗證有門檻，標準平台板卡仍有同業替代；2026–27 需求與替代性：板卡與伺服器同業競爭，需求強',
    'source_url' => 'https://www.gigabyte.com/Enterprise',
    'assessed_at' => '2026-10-04',
  ),
  60 => 
  array (
    'stock_code' => '2324',
    'stock_name' => '仁寶',
    'risk_percent' => 42,
    'basis' => '客製研發量產品質全球製造增加轉單成本，成熟ODM仍可由同業承接；2026–27 需求與替代性：代工同業競爭，客戶可分單',
    'source_url' => 'https://www.compal.com/static/file/CompalIntro_2025_EN.pdf',
    'assessed_at' => '2026-10-04',
  ),
  61 => 
  array (
    'stock_code' => '4749',
    'stock_name' => '新應材',
    'risk_percent' => 24,
    'basis' => '電子化學品與光阻材料需通過晶圓廠認證，轉換需重新驗證；日商仍有競爭；2026–27 需求與替代性：電子級化學品需通過製程認證，需求隨先進製程成長',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  62 => 
  array (
    'stock_code' => '2395',
    'stock_name' => '研華',
    'risk_percent' => 20,
    'basis' => '工業嵌入式設計、軟體整合及長供貨週期提高換廠重新設計驗證成本；2026–27 需求與替代性：工控與邊緣 AI 需求穩健，客戶黏著度高',
    'source_url' => 'https://www2.advantech.com/DMS/Services/Longevity-Services.aspx',
    'assessed_at' => '2026-10-04',
  ),
  63 => 
  array (
    'stock_code' => '2449',
    'stock_name' => '京元電子',
    'risk_percent' => 24,
    'basis' => '自製高功率燒機設備、客製測試及軟硬整合提高高階測試轉換成本；2026–27 需求與替代性：測試產能需求強、偏緊',
    'source_url' => 'https://www.kyec.com.tw/en/Service/technology-innovation',
    'assessed_at' => '2026-10-04',
  ),
  64 => 
  array (
    'stock_code' => '8086',
    'stock_name' => '宏捷科',
    'risk_percent' => 33,
    'basis' => 'GaAs 代工需通過客戶認證與製程驗證，但與穩懋等同業競爭；2026–27 需求與替代性：GaAs 代工同業（穩懋）可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  65 => 
  array (
    'stock_code' => '2327',
    'stock_name' => '國巨',
    'risk_percent' => 38,
    'basis' => '車工規高可靠度特殊元件有認證門檻，標準被動元件仍有替代來源；2026–27 需求與替代性：被動元件價格競爭，需求回升',
    'source_url' => 'https://yageogroup.com/content/Resource%20Library/Financial/Yageo%202025%20Annual%20Report_e.pdf',
    'assessed_at' => '2026-10-04',
  ),
  66 => 
  array (
    'stock_code' => '5347',
    'stock_name' => '世界',
    'risk_percent' => 35,
    'basis' => '特殊製程晶圓代工涉及類比、電源管理及混合訊號設計，轉廠需製程移植與驗證；成熟製程仍有同業替代供給；2026–27 需求與替代性：成熟特殊製程仍有同業供給',
    'source_url' => 'https://www.nxp.com/company/about-nxp/newsroom/NW-VSMC-V2',
    'assessed_at' => '2026-10-04',
  ),
  67 => 
  array (
    'stock_code' => '2356',
    'stock_name' => '英業達',
    'risk_percent' => 38,
    'basis' => '伺服器與筆電代工需通過客戶認證，但客戶可將訂單分配其他 ODM；2026–27 需求與替代性：伺服器代工客戶可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  68 => 
  array (
    'stock_code' => '3533',
    'stock_name' => '嘉澤',
    'risk_percent' => 20,
    'basis' => '伺服器 CPU 插槽與連接器需配合平台規格認證，供應商集中；平台世代更替仍有被分單風險；2026–27 需求與替代性：伺服器 CPU 插槽寡占，需求強',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  69 => 
  array (
    'stock_code' => '1519',
    'stock_name' => '華城',
    'risk_percent' => 14,
    'basis' => '重電變壓器需通過電網與客戶規格認證，交期長；全球電力設備供不應求；2026–27 需求與替代性：電力設備需求強、交期長、供不應求',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  70 => 
  array (
    'stock_code' => '1795',
    'stock_name' => '美時',
    'risk_percent' => 38,
    'basis' => '學名藥專利到期後同業競爭多，轉換成本低；特殊劑型有一定門檻；2026–27 需求與替代性：學名藥競爭激烈，需求中性',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  71 => 
  array (
    'stock_code' => '3529',
    'stock_name' => '力旺',
    'risk_percent' => 10,
    'basis' => '嵌入式非揮發性記憶體 IP 與晶圓廠製程綁定，客戶更換需重新驗證，市場高度集中；2026–27 需求與替代性：NVM IP 與客戶製程綁定',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  72 => 
  array (
    'stock_code' => '2317',
    'stock_name' => '鴻海',
    'risk_percent' => 27,
    'basis' => '全球量產軟硬整合供應鏈規模提高移轉難度，訂單仍可分配其他代工廠；2026–27 需求與替代性：AI 伺服器需求強、規模優勢',
    'source_url' => 'https://www.foxconn.com/en-us/about/group-profile',
    'assessed_at' => '2026-10-04',
  ),
  73 => 
  array (
    'stock_code' => '2382',
    'stock_name' => '廣達',
    'risk_percent' => 28,
    'basis' => 'AI 伺服器 ODM 具規模與設計能力，需求供不應求，但大客戶可分單其他 ODM；2026–27 需求與替代性：AI 伺服器需求強、客戶集中',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  74 => 
  array (
    'stock_code' => '9914',
    'stock_name' => '美利達',
    'risk_percent' => 52,
    'basis' => '自行車 OEM／品牌可被其他廠商替代，需求疲弱使客戶更易調整訂單；2026–27 需求與替代性：自行車需求疲弱，OEM 可替代',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  75 => 
  array (
    'stock_code' => '6271',
    'stock_name' => '同欣電',
    'risk_percent' => 28,
    'basis' => '陶瓷基板與感測封裝需通過車用認證，更換需重新驗證；2026–27 需求與替代性：車用與工業封裝需求穩定',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  76 => 
  array (
    'stock_code' => '1590',
    'stock_name' => '亞德客-KY',
    'risk_percent' => 35,
    'basis' => '氣動元件同業與陸廠競爭，標準品替代性中等，品牌通路具黏著度；2026–27 需求與替代性：工業自動化循環復甦，陸廠競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  77 => 
  array (
    'stock_code' => '6414',
    'stock_name' => '樺漢',
    'risk_percent' => 35,
    'basis' => '工業電腦客製與長期供貨黏著，但標準品仍可被同業替代；2026–27 需求與替代性：工業電腦需求穩定',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  78 => 
  array (
    'stock_code' => '6782',
    'stock_name' => '視陽',
    'risk_percent' => 35,
    'basis' => '視光與眼科產品具法規與通路門檻，但產品可被同業替代；2026–27 需求與替代性：眼視光需求穩定',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  79 => 
  array (
    'stock_code' => '4771',
    'stock_name' => '望隼',
    'risk_percent' => 38,
    'basis' => '公開資料有限，依生技醫療產業一般替代風險估計；2026–27 需求與替代性：產業屬性中性，公開資料有限',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  80 => 
  array (
    'stock_code' => '5269',
    'stock_name' => '祥碩',
    'risk_percent' => 18,
    'basis' => 'USB／PCIe 控制晶片與主要平台客戶綁定，替代需重新設計驗證；客戶集中為主要風險；2026–27 需求與替代性：高速傳輸控制器客戶集中',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  81 => 
  array (
    'stock_code' => '3023',
    'stock_name' => '信邦',
    'risk_percent' => 38,
    'basis' => '連接器與線束可被同業分單，部分產品需客戶認證；2026–27 需求與替代性：連接線束同業多',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  82 => 
  array (
    'stock_code' => '2379',
    'stock_name' => '瑞昱',
    'risk_percent' => 32,
    'basis' => '網通與多媒體 IC 同業競爭，客戶可切換方案但需重新驗證；2026–27 需求與替代性：網通 IC 同業競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  83 => 
  array (
    'stock_code' => '4938',
    'stock_name' => '和碩',
    'risk_percent' => 45,
    'basis' => '代工客戶集中，品牌客戶可將訂單分配其他 EMS；2026–27 需求與替代性：代工客戶集中、可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  84 => 
  array (
    'stock_code' => '8069',
    'stock_name' => '元太',
    'risk_percent' => 15,
    'basis' => '電子紙專利與供應鏈近乎寡占，客戶短期缺少同等替代；2026–27 需求與替代性：電子紙近乎寡占',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  85 => 
  array (
    'stock_code' => '6491',
    'stock_name' => '晶碩',
    'risk_percent' => 35,
    'basis' => '隱形眼鏡 ODM 需通過法規與客戶認證，但品牌客戶可分配其他代工廠；2026–27 需求與替代性：隱形眼鏡需求穩定，代工可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  86 => 
  array (
    'stock_code' => '4966',
    'stock_name' => '譜瑞-KY',
    'risk_percent' => 25,
    'basis' => '高速介面 IC 與客戶平台綁定，替代需重新設計；客戶集中為主要風險；2026–27 需求與替代性：高速介面 IC 客戶集中',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  87 => 
  array (
    'stock_code' => '2353',
    'stock_name' => '宏碁',
    'risk_percent' => 48,
    'basis' => '品牌 PC 市場競爭激烈，通路客戶易切換品牌；2026–27 需求與替代性：品牌 PC 同業競爭、需求中性',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  88 => 
  array (
    'stock_code' => '2458',
    'stock_name' => '義隆',
    'risk_percent' => 40,
    'basis' => '人機介面 IC 同業競爭多，客戶可切換方案；2026–27 需求與替代性：觸控／嵌入式 IC 同業競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  89 => 
  array (
    'stock_code' => '1476',
    'stock_name' => '儒鴻',
    'risk_percent' => 30,
    'basis' => '機能性紡織與品牌客戶長期合作，具開發與品質門檻；品牌客戶可分單其他廠；2026–27 需求與替代性：紡織客戶集中、黏著度高',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  90 => 
  array (
    'stock_code' => '3008',
    'stock_name' => '大立光',
    'risk_percent' => 28,
    'basis' => '高階鏡頭與客戶產品綁定，專利與製程領先；客戶可扶植其他供應商；2026–27 需求與替代性：手機鏡頭客戶集中、陸廠追趕',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  91 => 
  array (
    'stock_code' => '3034',
    'stock_name' => '聯詠',
    'risk_percent' => 38,
    'basis' => '顯示驅動 IC 同業競爭激烈，客戶可切換方案；2026–27 需求與替代性：顯示驅動 IC 同業競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  92 => 
  array (
    'stock_code' => '3406',
    'stock_name' => '玉晶光',
    'risk_percent' => 38,
    'basis' => '手機鏡頭同業競爭，客戶可分配訂單給其他供應商；2026–27 需求與替代性：手機鏡頭同業競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  93 => 
  array (
    'stock_code' => '6279',
    'stock_name' => '胡連',
    'risk_percent' => 36,
    'basis' => '連接器需通過車廠認證，但同業競爭、客戶可分單；2026–27 需求與替代性：車用連接器同業多',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  94 => 
  array (
    'stock_code' => '6409',
    'stock_name' => '旭隼',
    'risk_percent' => 35,
    'basis' => '公開資料有限，依電子設備產業一般替代風險估計；2026–27 需求與替代性：電子設備產業中性',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  95 => 
  array (
    'stock_code' => '1513',
    'stock_name' => '中興電',
    'risk_percent' => 16,
    'basis' => '重電設備需通過電力公司規格與資格，需求強、交期長；2026–27 需求與替代性：電力設備需求強、供不應求',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  96 => 
  array (
    'stock_code' => '1216',
    'stock_name' => '統一',
    'risk_percent' => 25,
    'basis' => '品牌與通路具黏著度，但民生品可被其他品牌替代；2026–27 需求與替代性：民生消費品牌通路穩固',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  97 => 
  array (
    'stock_code' => '2884',
    'stock_name' => '玉山金',
    'risk_percent' => 25,
    'basis' => '金融業無典型訂單概念，以客戶流失風險估計；同業競爭激烈；2026–27 需求與替代性：金融業無典型訂單，指客戶流失風險',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  98 => 
  array (
    'stock_code' => '1477',
    'stock_name' => '聚陽',
    'risk_percent' => 30,
    'basis' => '成衣 ODM 與品牌客戶長期合作，需通過品質與交期審核；品牌可分單；2026–27 需求與替代性：成衣 ODM 客戶集中',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  99 => 
  array (
    'stock_code' => '2912',
    'stock_name' => '統一超',
    'risk_percent' => 15,
    'basis' => '通路網路與品牌黏著度高，規模優勢明顯；2026–27 需求與替代性：便利商店通路穩固',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  100 => 
  array (
    'stock_code' => '2882',
    'stock_name' => '國泰金',
    'risk_percent' => 25,
    'basis' => '金融業無典型訂單概念，以客戶流失風險估計；2026–27 需求與替代性：金融業無典型訂單，指客戶流失風險',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  101 => 
  array (
    'stock_code' => '2881',
    'stock_name' => '富邦金',
    'risk_percent' => 25,
    'basis' => '金融業無典型訂單概念，以客戶流失風險估計；2026–27 需求與替代性：金融業無典型訂單，指客戶流失風險',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  102 => 
  array (
    'stock_code' => '8464',
    'stock_name' => '億豐',
    'risk_percent' => 38,
    'basis' => '品牌客戶可將訂單分配其他代工廠；2026–27 需求與替代性：品牌客戶可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  103 => 
  array (
    'stock_code' => '6669',
    'stock_name' => '緯穎',
    'risk_percent' => 30,
    'basis' => '雲端伺服器客製設計與客戶綁定，但大型客戶集中，可分單其他 ODM；2026–27 需求與替代性：雲端客戶集中，AI 需求強',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  104 => 
  array (
    'stock_code' => '2357',
    'stock_name' => '華碩',
    'risk_percent' => 45,
    'basis' => '品牌 PC 與伺服器同業競爭，客戶可切換；2026–27 需求與替代性：品牌 PC 與伺服器競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  105 => 
  array (
    'stock_code' => '1319',
    'stock_name' => '東陽',
    'risk_percent' => 32,
    'basis' => '汽車零組件需通過車廠認證，但可被其他供應商分單；2026–27 需求與替代性：車用售後件客戶認證',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  106 => 
  array (
    'stock_code' => '6890',
    'stock_name' => '來億-KY',
    'risk_percent' => 38,
    'basis' => '品牌客戶可將訂單分配其他代工廠；2026–27 需求與替代性：運動休閒品牌客戶可分單',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  107 => 
  array (
    'stock_code' => '5871',
    'stock_name' => '中租-KY',
    'risk_percent' => 30,
    'basis' => '租賃與金融服務同業競爭，客戶轉換成本中等；2026–27 需求與替代性：租賃金融同業競爭',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  108 => 
  array (
    'stock_code' => '2886',
    'stock_name' => '兆豐金',
    'risk_percent' => 25,
    'basis' => '金融業無典型訂單概念，以客戶流失風險估計；2026–27 需求與替代性：金融業無典型訂單，指客戶流失風險',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  109 => 
  array (
    'stock_code' => '2891',
    'stock_name' => '中信金',
    'risk_percent' => 25,
    'basis' => '金融業無典型訂單概念，以客戶流失風險估計；2026–27 需求與替代性：金融業無典型訂單，指客戶流失風險',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  110 => 
  array (
    'stock_code' => '9910',
    'stock_name' => '豐泰',
    'risk_percent' => 40,
    'basis' => '運動鞋代工與品牌客戶長期合作，但品牌可分配其他代工廠；2026–27 需求與替代性：運動鞋代工客戶集中',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  111 => 
  array (
    'stock_code' => '9938',
    'stock_name' => '百和',
    'risk_percent' => 42,
    'basis' => '拉鍊與配件可被同業替代，與品牌客戶長期合作具黏著度；2026–27 需求與替代性：配件業同業多',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  112 => 
  array (
    'stock_code' => '2618',
    'stock_name' => '長榮航',
    'risk_percent' => 50,
    'basis' => '航空運輸同質化高，乘客可選擇其他航空；2026–27 需求與替代性：航空同業競爭、需求中性',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  113 => 
  array (
    'stock_code' => '2610',
    'stock_name' => '華航',
    'risk_percent' => 50,
    'basis' => '航空運輸同質化高，乘客可選擇其他航空；2026–27 需求與替代性：航空同業競爭、需求中性',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  114 => 
  array (
    'stock_code' => '2603',
    'stock_name' => '長榮',
    'risk_percent' => 60,
    'basis' => '貨櫃航運服務同質化高，貨主可選擇其他船公司；2026–27 需求與替代性：貨櫃航運運價循環，同質化高',
    'source_url' => NULL,
    'assessed_at' => '2026-10-04',
  ),
  115 => 
  array (
    'stock_code' => '4971',
    'stock_name' => 'IET-KY',
    'risk_percent' => 18,
    'basis' => 'MBE磊晶與客製材料結構具門檻，替換需驗證元件性能及製程匹配',
    'source_url' => 'https://intelliepi.com/products/',
    'assessed_at' => '2026-10-01',
  ),
  116 => 
  array (
    'stock_code' => '7711',
    'stock_name' => '永擎',
    'risk_percent' => 35,
    'basis' => '伺服器客製及ODM/JDM有黏著度，共通晶片平台仍可選其他系統商',
    'source_url' => 'https://www.asrockrack.com/general/Investor/AnnualReport%28115%29.pdf',
    'assessed_at' => '2026-10-01',
  ),
  117 => 
  array (
    'stock_code' => '2313',
    'stock_name' => '華通',
    'risk_percent' => 35,
    'basis' => 'HDI微孔高密度線路有製程門檻，合格同業仍可承接',
    'source_url' => 'https://www.compeq.com.tw/product_view.php?gid=f7b2917a-9a04-11ec-89fb-005056a9caf8',
    'assessed_at' => '2026-10-01',
  ),
) as $row) {
            DB::table('tw_stock_order_loss_risks')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_order_loss_risks');
    }
};
