@extends('customer-admin.layout')
@php
    $editing = $record->exists;
@endphp
@section('title', ($editing?'編輯':'新增').$config['singular'])
@section('content')
<form id="module-form" class="panel form-panel" method="post" enctype="multipart/form-data" action="{{ $editing ? route('customer-admin.module.update',[$module,$record->id]) : route('customer-admin.module.store',$module) }}">
    @csrf @if($editing) @method('PUT') @endif
    <div class="form-grid">
        @if($module==='orders')
            <section class="customer-lookup">
                <div class="customer-lookup-head">
                    <div class="field">
                        <label for="customer_phone_lookup">用電話快速帶入客戶 <span class="hint">輸入部分號碼即可搜尋</span></label>
                        <input id="customer_phone_lookup" name="customer_phone_lookup" list="order-phone-history" autocomplete="off" placeholder="例如：90、0912、02">
                        <datalist id="order-phone-history">
                            @foreach($options['orderCustomers'] as $customerOption)
                                @if($customerOption['phone'])<option value="{{ $customerOption['phone'] }}">{{ $customerOption['name'] }}｜市話</option>@endif
                                @if($customerOption['mobile'])<option value="{{ $customerOption['mobile'] }}">{{ $customerOption['name'] }}｜手機</option>@endif
                            @endforeach
                        </datalist>
                    </div>
                    <div style="color:var(--muted);font-size:13px;line-height:1.7">選定電話後，會自動帶入客戶、接洽人及配送地址。下方資訊可先核對，再繼續選商品。</div>
                </div>
                <div id="customer-quick-info" class="customer-info"><div class="customer-info-empty">尚未選擇客戶</div></div>
            </section>
        @endif
        @foreach($config['fields'] as $name=>$field)
            @php
                $type = $field['type'] ?? 'text';
                $value = old($name, data_get($record, $name));
            @endphp
            <div class="field {{ !empty($field['wide'])?'wide':'' }}">
                <label for="{{ $name }}">{{ $field['label'] }} @if(!empty($field['required']))<b class="required">*</b>@else<span class="hint">選填</span>@endif</label>
                @if($type==='textarea')
                    <textarea id="{{ $name }}" name="{{ $name }}" placeholder="{{ $field['placeholder']??'' }}">{{ $value }}</textarea>
                @elseif($type==='select')
                    <select id="{{ $name }}" name="{{ $name }}"><option value="">請選擇（可留空）</option>@foreach($field['options'] as $optionValue=>$optionLabel)<option value="{{ $optionValue }}" @selected((string)$value===(string)$optionValue)>{{ $optionLabel }}</option>@endforeach</select>
                @elseif($type==='relation')
                    <select id="{{ $name }}" name="{{ $name }}"><option value="">請選擇（可留空）</option>@foreach($options[$field['source']] as $optionValue=>$optionLabel)<option value="{{ $optionValue }}" @selected((string)$value===(string)$optionValue)>{{ is_array($optionLabel)?$optionLabel['label']:$optionLabel }}</option>@endforeach</select>
                @else
                    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" step="{{ $field['step']??'' }}" placeholder="{{ $field['placeholder']??'' }}" @if(!empty($field['datalist'])) list="{{ $name }}-history" autocomplete="off" @endif @required(!empty($field['required']))>
                    @if(!empty($field['datalist']))
                        <datalist id="{{ $name }}-history">
                            @foreach($options[$field['datalist']] as $suggestion)<option value="{{ $suggestion }}"></option>@endforeach
                        </datalist>
                    @endif
                @endif
            </div>
        @endforeach

        @if($module==='products')
            <div class="field wide">
                <label>商品圖片 <span class="hint">選填，JPG／PNG／WebP／GIF，最大 8MB</span></label>
                <div id="image-drop" class="image-drop" tabindex="0">
                    <input id="image-input" type="file" name="image" accept="image/*" hidden>
                    <div id="upload-copy" class="upload-copy" @if($record->image_path) hidden @endif>
                        <div class="upload-icon">⬆</div><strong>按一下選擇圖片，或把圖片拖曳到這裡</strong>
                        <small>也可以直接 Ctrl+V 貼上剪貼簿圖片，再按 Enter 儲存上傳</small>
                    </div>
                    <img id="image-preview" src="{{ $record->image_path ? Storage::url($record->image_path) : '' }}" alt="商品圖片預覽" @if(!$record->image_path) hidden @endif>
                </div>
                @if($record->image_path)<label class="remove-check"><input type="checkbox" name="remove_image" value="1"> 移除目前圖片</label>@endif
            </div>
        @endif
    </div>

    @if($module==='orders')
        @php
            $initialItems = old('items', $record->exists
                ? $record->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'notes' => $item->notes,
                ])->all()
                : [[
                    'product_id' => '',
                    'product_name' => '',
                    'quantity' => 1,
                    'unit_price' => '',
                    'notes' => '',
                ]]);
        @endphp
        <section class="order-items"><div style="display:flex;justify-content:space-between;align-items:center"><div><h3>訂單商品 <b class="required">*</b></h3><small style="color:var(--muted)">選商品後會自動帶入價格，仍可依本次報價調整。</small></div><button id="add-item" class="btn btn-sm btn-secondary" type="button">＋ 加一項</button></div>
            <div id="item-list">
                @foreach($initialItems as $index=>$item)
                    <div class="item-row">
                        <div><label>商品</label><select class="product-select" name="items[{{ $index }}][product_id]" required><option value="">請選擇商品</option>@foreach($options['products'] as $id=>$product)<option value="{{ $id }}" data-name="{{ $product['name'] }}" data-price="{{ $product['price'] }}" @selected((string)($item['product_id']??'')===(string)$id)>{{ $product['label'] }}</option>@endforeach</select><input class="product-name" type="hidden" name="items[{{ $index }}][product_name]" value="{{ $item['product_name']??'' }}"></div>
                        <div><label>數量</label><input class="qty" name="items[{{ $index }}][quantity]" type="number" min=".01" step=".01" value="{{ $item['quantity']??1 }}" required></div>
                        <div><label>單價</label><input class="unit-price" name="items[{{ $index }}][unit_price]" type="number" min="0" step=".01" value="{{ $item['unit_price']??'' }}" required></div>
                        <div><label>小計</label><div class="line-total">$0</div><input name="items[{{ $index }}][notes]" type="hidden" value="{{ $item['notes']??'' }}"></div>
                        <button class="btn btn-sm btn-danger remove-item" type="button">×</button>
                    </div>
                @endforeach
            </div>
            <div class="total-bar"><span>商品小計</span><strong id="items-total">$0.00</strong></div>
        </section>
    @endif

    <div class="form-footer"><a class="btn btn-secondary" href="{{ route('customer-admin.module.index',$module) }}">取消</a><button class="btn btn-primary" type="submit">✓ 儲存{{ $config['singular'] }}</button></div>
</form>
@endsection
@php
    $jsProducts = [];
    foreach ($options['products'] as $productId => $productOption) {
        $jsProducts[] = [
            'id' => $productId,
            'label' => $productOption['label'],
            'name' => $productOption['name'],
            'price' => $productOption['price'],
        ];
    }
    $jsOrderCustomers = $options['orderCustomers'];
@endphp
@push('scripts')
@if($module==='products')
<script>
(() => {
    const zone=document.querySelector('#image-drop'), input=document.querySelector('#image-input'), preview=document.querySelector('#image-preview'), copy=document.querySelector('#upload-copy');
    const setFile=file => {
        if(!file || !file.type.startsWith('image/')) return;
        const transfer=new DataTransfer(); transfer.items.add(file); input.files=transfer.files;
        preview.src=URL.createObjectURL(file); preview.hidden=false; copy.hidden=true;
    };
    zone.addEventListener('click',e=>{if(e.target!==preview) input.click()});
    input.addEventListener('change',()=>setFile(input.files[0]));
    ['dragenter','dragover'].forEach(type=>zone.addEventListener(type,e=>{e.preventDefault();zone.classList.add('dragging')}));
    ['dragleave','drop'].forEach(type=>zone.addEventListener(type,e=>{e.preventDefault();zone.classList.remove('dragging')}));
    zone.addEventListener('drop',e=>setFile([...e.dataTransfer.files].find(f=>f.type.startsWith('image/'))));
    document.addEventListener('paste',e=>{const file=[...e.clipboardData.items].find(i=>i.type.startsWith('image/'))?.getAsFile();if(file){setFile(file);zone.focus()}});
    zone.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();if(input.files.length) document.querySelector('#module-form').requestSubmit();else input.click()}});
})();
</script>
@endif
@if($module==='orders')
<script>
(() => {
    const list=document.querySelector('#item-list'), add=document.querySelector('#add-item');
    const productOptions={{ Illuminate\Support\Js::from($jsProducts) }};
    const orderCustomers={{ Illuminate\Support\Js::from($jsOrderCustomers) }};
    const phoneLookup=document.querySelector('#customer_phone_lookup'), customerSelect=document.querySelector('#customer_id'), contactSelect=document.querySelector('#contact_id'), addressSelect=document.querySelector('#address_id'), info=document.querySelector('#customer-quick-info');
    const display=value=>value||'—';
    const safe=value=>String(display(value)).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    function renderCustomer(customer){
        if(!customer){info.innerHTML='<div class="customer-info-empty">找不到完全相符的電話，請繼續輸入或從下拉選擇。</div>';return}
        info.innerHTML=[
            ['客戶名稱',customer.name],['客戶編號',customer.code],['市話',customer.phone],['手機電話',customer.mobile],
            ['地址',customer.customer_address],['統一編號',customer.tax_id],['產業',customer.industry],['Email',customer.email],
            ['狀態',customer.status],['接洽人',customer.contact],['配送地址',customer.address]
        ].map(([label,value])=>`<div class="customer-info-item"><small>${label}</small><span title="${safe(value)}">${safe(value)}</span></div>`).join('');
    }
    function applyCustomer(customer, syncSelects=true, chosenPhone=null){
        if(!customer){renderCustomer(null);return}
        if(syncSelects){
            customerSelect.value=String(customer.id);
            contactSelect.value=customer.contact_id?String(customer.contact_id):'';
            addressSelect.value=customer.address_id?String(customer.address_id):'';
        }
        phoneLookup.value=chosenPhone||customer.mobile||customer.phone||'';
        renderCustomer(customer);
    }
    const customerByPhone=value=>orderCustomers.find(item=>item.phone===value||item.mobile===value);
    phoneLookup.addEventListener('input',()=>{const customer=customerByPhone(phoneLookup.value);if(customer)applyCustomer(customer,true,phoneLookup.value)});
    phoneLookup.addEventListener('change',()=>{const customer=customerByPhone(phoneLookup.value);applyCustomer(customer,true,phoneLookup.value)});
    customerSelect.addEventListener('change',()=>applyCustomer(orderCustomers.find(item=>String(item.id)===customerSelect.value),false));
    const selectedCustomer=orderCustomers.find(item=>String(item.id)===customerSelect.value);if(selectedCustomer)applyCustomer(selectedCustomer,false);
    let nextIndex=list.children.length;
    function recalc(){
        let total=0;
        list.querySelectorAll('.item-row').forEach(row=>{const line=(parseFloat(row.querySelector('.qty').value)||0)*(parseFloat(row.querySelector('.unit-price').value)||0);row.querySelector('.line-total').textContent='$'+line.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});total+=line});
        document.querySelector('#items-total').textContent='$'+total.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    }
    function bind(row){
        row.querySelector('.product-select').addEventListener('change',e=>{const option=e.target.selectedOptions[0];row.querySelector('.product-name').value=option?.dataset.name||'';if(option?.dataset.price)row.querySelector('.unit-price').value=option.dataset.price;recalc()});
        row.querySelectorAll('.qty,.unit-price').forEach(el=>el.addEventListener('input',recalc));
        row.querySelector('.remove-item').addEventListener('click',()=>{if(list.children.length>1)row.remove();else{row.querySelector('.product-select').value='';row.querySelector('.unit-price').value=''}recalc()});
    }
    add.addEventListener('click',()=>{const row=document.createElement('div');row.className='item-row';const options=productOptions.map(p=>`<option value="${p.id}" data-name="${p.name.replaceAll('"','&quot;')}" data-price="${p.price}">${p.label}</option>`).join('');row.innerHTML=`<div><label>商品</label><select class="product-select" name="items[${nextIndex}][product_id]" required><option value="">請選擇商品</option>${options}</select><input class="product-name" type="hidden" name="items[${nextIndex}][product_name]"></div><div><label>數量</label><input class="qty" name="items[${nextIndex}][quantity]" type="number" min=".01" step=".01" value="1" required></div><div><label>單價</label><input class="unit-price" name="items[${nextIndex}][unit_price]" type="number" min="0" step=".01" required></div><div><label>小計</label><div class="line-total">$0.00</div><input type="hidden" name="items[${nextIndex}][notes]"></div><button class="btn btn-sm btn-danger remove-item" type="button">×</button>`;nextIndex++;list.appendChild(row);bind(row);row.querySelector('select').focus()});
    list.querySelectorAll('.item-row').forEach(bind);recalc();
})();
</script>
@endif
@endpush
