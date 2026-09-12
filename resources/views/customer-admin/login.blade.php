<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>登入｜Star CRM</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;color:#f5f7ff;font-family:Inter,"Microsoft JhengHei",sans-serif;background:#070916;overflow:hidden}
        body:before{content:"";position:fixed;inset:-40%;background:conic-gradient(from 180deg,rgba(124,92,255,.25),transparent 25%,rgba(54,217,239,.18),transparent 55%,rgba(255,95,200,.2));animation:spin 22s linear infinite;filter:blur(80px)}@keyframes spin{to{transform:rotate(360deg)}}
        .stars{position:fixed;inset:0;background-image:radial-gradient(#fff 1px,transparent 1px);background-size:48px 48px;opacity:.08}
        .card{position:relative;width:min(430px,calc(100% - 30px));padding:38px;border:1px solid rgba(255,255,255,.11);border-radius:25px;background:rgba(12,16,35,.8);backdrop-filter:blur(25px);box-shadow:0 35px 100px rgba(0,0,0,.45)}
        .mark{width:58px;height:58px;display:grid;place-items:center;margin-bottom:24px;border-radius:18px;background:linear-gradient(135deg,#7c5cff,#36d9ef);font-size:25px;font-weight:900;box-shadow:0 0 35px rgba(124,92,255,.45)}
        h1{margin:0 0 9px;font-size:29px}p{margin:0 0 28px;color:#99a3bf;line-height:1.6}.field{margin:16px 0}label{display:block;margin-bottom:8px;color:#cbd2e8;font-size:13px;font-weight:700}
        input{width:100%;padding:13px 14px;color:white;background:rgba(3,6,18,.55);border:1px solid rgba(255,255,255,.12);border-radius:12px;outline:none;font:inherit}input:focus{border-color:#36d9ef;box-shadow:0 0 0 3px rgba(54,217,239,.1)}
        button{width:100%;margin-top:12px;padding:13px;border:0;border-radius:12px;background:linear-gradient(135deg,#7c5cff,#6254e9);box-shadow:0 12px 30px rgba(124,92,255,.3);color:white;font:700 15px inherit;cursor:pointer}.error{padding:11px 13px;border-radius:10px;background:rgba(255,76,117,.1);color:#ff9bb3;font-size:13px}.secure{text-align:center;margin-top:20px;color:#626d8b;font-size:12px}
    </style>
<style>
body{background:radial-gradient(ellipse at top,#263858,transparent 65%),#10151f;overflow:auto;padding:28px 0;color-scheme:dark}body:before,.stars{display:none}.card{background:#192332;border-color:#3b4960;border-radius:18px;box-shadow:0 20px 60px #0003;backdrop-filter:none}.mark{background:#6974d9;box-shadow:none;border-radius:14px}.card p{color:#b1bed1}input{background:#111a27;border-color:#46536a;border-radius:8px;min-height:48px}input:focus{border-color:#a7b2ff;outline:2px solid #a7b2ff;outline-offset:2px;box-shadow:none}button{background:#6974d9;box-shadow:none;border-radius:8px;font:700 15px "Microsoft JhengHei",sans-serif;min-height:48px}button:hover{background:#7985eb}button:focus-visible{outline:2px solid #a7b2ff;outline-offset:3px}.secure{color:#a5b1c3}.password-wrap{position:relative}.password-wrap input{padding-right:72px}.password-toggle{position:absolute;right:6px;top:5px;width:58px;min-height:38px;margin:0;padding:6px;background:#26344b;font-size:13px}.card .error{margin-bottom:16px}@media(max-width:480px){.card{padding:28px 24px}h1{font-size:26px}}@media(prefers-reduced-motion:reduce){*{animation:none!important}}
</style></head>
<body><div class="stars"></div>
<form class="card" method="post" action="{{ route('customer-admin.login.submit') }}">
    @csrf<div class="mark">S</div><h1>歡迎回來</h1><p>登入 Star CRM，集中管理客戶、商品與每一筆訂單。</p>
    @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
    <div class="field"><label for="username">管理員帳號</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus></div>
    <div class="field"><label for="password">登入密碼</label><div class="password-wrap"><input id="password" type="password" name="password" autocomplete="current-password" required><button class="password-toggle" type="button" aria-controls="password" aria-pressed="false">顯示</button></div></div>
    <button type="submit">登入管理後台 →</button><div class="secure">◆ 加密連線 · 工作階段保護</div>
</form><script>
document.querySelector('.password-toggle').addEventListener('click',function(){const input=document.getElementById('password');const show=input.type==='password';input.type=show?'text':'password';this.textContent=show?'隱藏':'顯示';this.setAttribute('aria-pressed',String(show));});
</script></body></html>
