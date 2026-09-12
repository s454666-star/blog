<style>
/* CRM-only presentation. Keep native form semantics and a single page scroll. */
:root{color-scheme:dark;--bg:#10151f;--panel:#19212e;--line:#303b4b;--text:#edf1f7;--muted:#a5b1c3;--purple:#858cf6;--cyan:#aeb9ff;--row-odd:#19212e;--row-even:#1c2533;--row-hover:#253249}
body{background:radial-gradient(ellipse at 85% 0,#202c46 0,transparent 45%),var(--bg);line-height:1.55}
body:before,body:after,.stat:after{display:none}
.app{grid-template-columns:220px minmax(0,1fr)}
.sidebar{background:#141b27;backdrop-filter:none;padding:26px 14px}
.brand{font-size:18px;letter-spacing:1px}.brand-mark{background:#6974d9;box-shadow:none;border-radius:12px}
.nav-label{color:var(--muted);letter-spacing:1px}.nav-link{border-radius:9px;transition:background .15s,color .15s}
.nav-link:hover{background:#222e41;box-shadow:none}.nav-link.active{background:#2a3552;box-shadow:inset 3px 0 #9ba5ff}
.nav-icon{color:#bdc7e0}.logout button{min-height:44px}
.content{width:100%;max-width:1760px;margin-inline:auto;padding:32px clamp(20px,3vw,48px) 64px}
.eyebrow{color:var(--muted);font-size:11px;letter-spacing:1.4px}.page-title{font-size:28px;font-weight:750}.topbar{margin-bottom:26px}
.panel{background:var(--panel);border-radius:14px;box-shadow:0 6px 20px #00000012;backdrop-filter:none}
.btn{min-height:44px;border-radius:9px;font-size:14px;transition:background .15s,border-color .15s;white-space:nowrap}.btn:hover{transform:none;filter:none}
.btn-primary{background:#6974d9;box-shadow:none}.btn-primary:hover{background:#7985eb}.btn-secondary{background:#222d3e;border-color:#3a465a}.btn-secondary:hover{background:#303e53}
.btn-sm{min-height:36px}.btn-danger{background:transparent;color:#f5a6b6;border-color:#704551}.btn-danger:hover{background:#4d2e3a}
.btn:disabled{opacity:.5;cursor:not-allowed}
input,select,textarea{background:#111a27;border-color:#3d4a5e;border-radius:8px;font-size:15px;transition:border-color .15s;min-height:44px;line-height:1.4}
input::placeholder,textarea::placeholder{color:#8795ab}input:focus,select:focus,textarea:focus{border-color:#a7b2ff;box-shadow:none;outline:2px solid #a7b2ff;outline-offset:2px}
a:focus-visible,button:focus-visible,.table-wrap:focus-visible{outline:2px solid #a7b2ff;outline-offset:3px}
input[type=checkbox]{min-height:auto;width:auto;accent-color:#858cf6}select{padding-right:36px}select option{background:#1b2637;color:var(--text)}
@supports(appearance:base-select){
 select,select::picker(select){appearance:base-select}
 select,.per-page-control select{display:inline-flex;align-items:center;justify-content:space-between;gap:12px;padding:0 12px;height:44px}
 select::picker-icon{content:'';width:12px;height:8px;flex:none;background:currentColor;clip-path:polygon(0 0,50% 65%,100% 0,100% 35%,50% 100%,0 35%);rotate:none;transform:none;color:#acb9cf}
 select::picker(select){border:1px solid #4a5870;border-radius:10px;background:#1b2637;color:var(--text);padding:5px;box-shadow:0 12px 28px #0006;margin-top:6px;max-height:320px}
 select option{padding:10px 12px;border-radius:6px;min-height:42px}select option:hover,select option:focus{background:#303f59}select option:checked{background:#344366}select option::checkmark{color:#c0c8ff}
}
.field label,.query-fields label{font-size:14px;color:#d7dfeb}.hint{font-size:12px;color:var(--muted)}.required{color:#f1a3b3}
.form-panel{padding:26px}.form-grid{gap:22px 24px}.field{min-width:0}.form-footer{align-items:center;flex-wrap:wrap}.activity-log-status{margin-right:auto}
.customer-lookup{background:#1e293b;border-color:#3b4a63;border-radius:11px}.customer-info-item small{color:var(--muted)}
.order-items{background:#151e2c;border-radius:11px}.item-row{grid-template-columns:minmax(160px,2.3fr) minmax(80px,.65fr) minmax(100px,.9fr) minmax(85px,.9fr) auto;gap:12px}.item-row label{font-size:13px}.line-total{height:44px;font-variant-numeric:tabular-nums}
.date-picker-popover{background:#202c3e;border-color:#4a5870;box-shadow:0 12px 32px #0006}.date-picker-day.selected{background:#6974d9}.date-picker-day{min-height:38px}
.table-tools{padding:18px 20px;align-items:center}.search{max-width:620px}.search span{top:50%;transform:translateY(-50%)}.per-page-control select{min-width:104px}
th{background:#29364a;color:#f1f4fb;font-size:14px;font-weight:750;letter-spacing:0;white-space:nowrap;padding:15px 18px;border-bottom:1px solid #53627a}
td{font-size:14px;padding:15px 18px;font-variant-numeric:tabular-nums;border-bottom:1px solid #303b4b}.sort-link{color:#f1f4fb}.sort-link b{color:#b1bdd3}.sort-link.active{color:#c3cbff}
.table-wrap{border-radius:0;max-height:none}.actions{align-items:center}.badge{background:#27364b;border-color:#41536e;color:#d4dff0;border-radius:6px;font-weight:600}
.crm-scroll-hint{padding:8px 20px;color:var(--muted);font-size:12px;border-bottom:1px solid var(--line)}
.crm-floating-head{position:fixed;z-index:15;overflow:hidden;box-shadow:0 5px 12px #0003;pointer-events:auto}.crm-floating-head[hidden]{display:none}.crm-floating-head table{table-layout:fixed;margin:0}
.pagination{background:#192331;border-color:var(--line);box-shadow:none;border-radius:0 0 14px 14px}.crm-pagination-link,.crm-pagination-page{background:#253247;color:#dce4f2!important;box-shadow:none;border:1px solid #41506a;min-height:40px}.crm-pagination-link:hover,.crm-pagination-page:hover{background:#344564;color:white!important;transform:none}.crm-pagination-page.active{background:#6974d9;color:white!important;box-shadow:none;border-color:#8994ed}.crm-pagination-disabled{opacity:.45}
.welcome{background:linear-gradient(110deg,#273553,#202b3d);border-color:#3e4e69;padding:24px 28px}.welcome h2{font-size:21px}.welcome p{color:#bac6da}.stat{padding:20px 22px;border-top:2px solid var(--accent)}.stat-label{margin:12px 0 5px;font-size:14px}.stat-value{font-size:28px;letter-spacing:-.5px;font-variant-numeric:tabular-nums}.stat-icon{font-size:20px}
.export-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.export-help{background:#202e43;color:#c9d7ee;border-color:#3c506c;margin-bottom:18px}.export-card{padding:24px;gap:16px}.export-card p{font-size:14px}.query-panel{padding:22px}.empty{padding:60px 24px;line-height:1.9}
.skip-link{position:fixed;top:-80px;left:20px;background:#6974d9;padding:12px;z-index:100;border-radius:8px}.skip-link:focus{top:12px}
@media(min-width:701px) and (max-width:1100px){.app{grid-template-columns:96px minmax(0,1fr)}.sidebar{padding:24px 8px}.brand span,.nav-label{display:none}.brand{padding-inline:0;justify-content:center}.nav-link{flex-direction:column;gap:4px;padding:12px 4px}.nav-link span,.logout span{display:block;font-size:12px}.logout{left:8px;right:8px}.topbar{align-items:flex-start}.top-actions{flex-wrap:wrap;justify-content:flex-end}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.item-row{grid-template-columns:repeat(2,minmax(0,1fr))}.item-row>div:first-child{grid-column:1/-1}}
@media(max-width:700px){.app{display:block}.sidebar{height:calc(76px + env(safe-area-inset-bottom));padding:6px 6px calc(6px + env(safe-area-inset-bottom));background:#141b27}.sidebar nav{flex:1;width:auto}.nav-link{flex:1;flex-direction:column;gap:2px;padding:7px 4px;border-radius:8px}.nav-link span{display:block;font-size:12px}.nav-link.active{box-shadow:inset 0 2px #9ba5ff}.logout{display:block;position:static;width:60px;margin:0 0 0 4px}.logout button{height:100%;padding:6px 2px;background:none;border:0;font-size:17px}.logout span{display:block;font-size:11px}.content{padding:22px 14px calc(104px + env(safe-area-inset-bottom))}.eyebrow{font-size:10px;letter-spacing:.7px}.topbar{flex-direction:column;gap:16px}.top-actions{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.top-actions .btn{padding:10px 8px;font-size:13px}.page-title{font-size:25px}.welcome{padding:20px}.welcome h2{font-size:18px}.stat{padding:16px}.stat-value{font-size:23px}.form-panel{padding:18px}.form-grid{gap:20px}.table-tools{align-items:stretch;padding:16px}.item-row{grid-template-columns:repeat(2,minmax(0,1fr))}.item-row>div:first-child{grid-column:1/-1}.order-items{padding:14px}.order-items>div:first-child{gap:12px;flex-wrap:wrap}.order-items small{display:block;margin-top:6px}.form-footer>.btn{flex:1}.activity-log-status{width:100%}.export-grid{grid-template-columns:1fr}.query-fields{grid-template-columns:1fr}.export-table-head{flex-wrap:wrap;gap:8px}.date-picker-popover{max-width:100%}.table-tool-actions{gap:8px}.total-bar{gap:16px}.stat-label{font-size:13px}}
.customer-info{gap:18px;margin-top:0}
@media(max-width:1100px){.customer-info{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.customer-info{grid-template-columns:1fr}.customer-lookup{padding:16px}.top-actions>.btn:only-child{grid-column:1/-1}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
</style>
