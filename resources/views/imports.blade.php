<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Catalog Import</title>
    <style>
        [hidden]{display:none!important}:root{font-family:Segoe UI,Arial,sans-serif;color:#202b36;background:#f4f5f7}*{box-sizing:border-box}body{margin:0}main{max-width:1100px;margin:0 auto;padding:42px 24px}header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px}h1{font-size:30px;letter-spacing:-1px;margin:0 0 8px}h2{font-size:18px;margin:0 0 18px}p{color:#697481;line-height:1.6;margin:0}.brand{font-size:12px;text-transform:uppercase;letter-spacing:2px;margin-bottom:12px;color:#4e6287;font-weight:700}.layout{display:grid;grid-template-columns:330px 1fr;gap:22px;align-items:start}.panel{background:white;border:1px solid #e0e4e9;border-radius:12px;padding:24px;margin-bottom:22px}.hint{font-size:13px;margin:12px 0 20px}input[type=file]{max-width:100%;font-size:13px;padding:14px 0}button,.link{font:inherit;font-size:14px;cursor:pointer;border:0;border-radius:7px;padding:11px 17px;background:#2d4c89;color:white;text-decoration:none;display:inline-block}button:hover{background:#203b70}button:disabled{opacity:.5;cursor:wait}.secondary{background:#edf1f8;color:#304c80}a{color:#304c80}code{background:#f0f2f6;padding:3px 5px;border-radius:4px;font-size:12px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:13px}th{text-align:left;color:#78818e;font-weight:500;border-bottom:1px solid #e5e8ed;padding:0 12px 12px 0}td{padding:14px 12px 14px 0;border-bottom:1px solid #f0f1f4;vertical-align:top}.file{font-weight:600;overflow-wrap:anywhere;max-width:230px}.status{display:inline-block;font-size:12px;padding:4px 8px;border-radius:5px;background:#edf1f8;color:#3b5688;white-space:nowrap}.completed{background:#e8f3ed;color:#276546}.failed{background:#fae9e8;color:#9c3a35}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:20px 0}.stat{background:#f6f7f9;padding:15px;border-radius:8px}.stat strong{display:block;font-size:26px}.stat span{font-size:12px;color:#73808c}.empty{padding:22px 0;color:#8a929e;font-size:14px}.actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px}.muted{font-size:12px;color:#88919c}#message{font-size:14px;min-height:24px;color:#9c3a35;margin-top:16px;overflow-wrap:anywhere}#details[hidden]{display:none}ul{padding-left:18px;font-size:13px;line-height:1.8}.small-button{padding:6px 10px;font-size:12px;background:#edf1f8;color:#304c80}.pagination{display:flex;gap:10px;align-items:center;margin-top:16px}.pagination button{padding:6px 10px}.right{text-align:right}@media(max-width:800px){.layout{grid-template-columns:1fr}header{align-items:start;gap:16px}main{padding:26px 16px}.panel{padding:20px}.stat strong{font-size:22px}}
    </style>
</head>
<body>
<main>
    <header><div><div class="brand">Catalog</div><h1>Product import</h1><p>Upload a CSV file, review the results, and update your catalog.</p></div><a class="link secondary" href="/sample.csv">Sample CSV</a></header>
    <div class="layout">
        <aside>
            <section class="panel"><h2>New import</h2>
                <form id="upload"><label for="file">CSV file</label><input id="file" type="file" accept=".csv,text/csv" required><p class="hint">UTF-8, up to 50 MB. Columns:<br><code>sku,name,price,quantity</code></p><button id="submit" type="submit">Upload</button></form>
            </section>
            <section class="panel" id="details" hidden><h2 id="detail-title">Results</h2><span id="detail-status" class="status"></span><div class="stats"><div class="stat"><strong id="processed">0</strong><span>Processed</span></div><div class="stat"><strong id="valid">0</strong><span>Valid</span></div><div class="stat"><strong id="invalid">0</strong><span>Errors</span></div></div><p id="last-error" class="hint"></p><ul id="errors"></ul><div class="actions"><a id="report" hidden>CSV report</a><button id="retry" class="secondary" hidden>Retry</button></div></section>
        </aside>
        <div>
            <section class="panel"><h2>Import history</h2><div class="table-wrap"><table><thead><tr><th>File</th><th>Status</th><th>Rows</th><th></th></tr></thead><tbody id="imports"></tbody></table></div><div class="pagination"><button id="imports-prev" class="secondary">←</button><span id="imports-page" class="muted"></span><button id="imports-next" class="secondary">→</button></div></section>
            <section class="panel"><h2>Catalog</h2><div class="table-wrap"><table><thead><tr><th>SKU</th><th>Name</th><th class="right">Price</th><th class="right">Quantity</th></tr></thead><tbody id="products"></tbody></table></div><div class="pagination"><button id="products-prev" class="secondary">←</button><span id="products-page" class="muted"></span><button id="products-next" class="secondary">→</button></div></section>
        </div>
    </div>
</main>
<script>
const $=id=>document.getElementById(id);
const labels={queued:'Queued',processing:'Processing',completed:'Completed',failed:'Failed'};
let selected=null, importsPage=1, productsPage=1, loading=false;
async function api(path,options={}){
    const response=await fetch('/api'+path,{...options,headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,...options.headers}});
    const data=await response.json();
    if(!response.ok)throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'The request failed.');
    return data;
}
function cell(row,text,className=''){const el=document.createElement('td');el.textContent=text;el.className=className;row.append(el);return el;}
function status(el,value){el.textContent=labels[value]||value;el.className='status '+value;}
function paginate(prefix,data){$(prefix+'-prev').disabled=data.current_page<=1;$(prefix+'-next').disabled=data.current_page>=data.last_page;$(prefix+'-page').textContent=data.current_page+' / '+data.last_page;}
async function refresh(){
    if(loading)return;loading=true;
    try{
        const [imports,products]=await Promise.all([api('/imports?page='+importsPage),api('/products?page='+productsPage)]);
        $('imports').replaceChildren();
        for(const item of imports.data){const row=document.createElement('tr');cell(row,item.filename,'file');const state=document.createElement('span');status(state,item.status);cell(row,'').append(state);cell(row,item.processed_rows);const action=cell(row,'');const button=document.createElement('button');button.className='small-button';button.textContent='Open';button.onclick=()=>show(item.id).catch(fail);action.append(button);$('imports').append(row);}
        if(!imports.data.length){const row=document.createElement('tr');const el=cell(row,'No imports yet.','empty');el.colSpan=4;$('imports').append(row);}
        $('products').replaceChildren();
        for(const item of products.data){const row=document.createElement('tr');cell(row,item.sku);cell(row,item.name);cell(row,item.price,'right');cell(row,item.quantity,'right');$('products').append(row);}
        if(!products.data.length){const row=document.createElement('tr');const el=cell(row,'The catalog is empty.','empty');el.colSpan=4;$('products').append(row);}
        paginate('imports',imports);paginate('products',products);
        if(selected)await show(selected);
    }finally{loading=false;}
}
async function show(id){
    selected=id;const result=await api('/imports/'+id);const item=result.data;$('details').hidden=false;$('detail-title').textContent=item.filename;status($('detail-status'),item.status);
    $('processed').textContent=item.processed_rows;$('valid').textContent=item.valid_rows;$('invalid').textContent=item.invalid_rows;$('last-error').textContent=item.last_error||'';
    $('errors').replaceChildren();for(const e of result.errors){const li=document.createElement('li');li.textContent='Row '+e.row_number+': '+e.message;$('errors').append(li);}
    $('report').hidden=!item.invalid_rows;$('report').href='/api/imports/'+id+'/errors';$('retry').hidden=item.status!=='failed';
}
function fail(error){$('message').textContent=error.message;}
$('upload').onsubmit=async event=>{event.preventDefault();const file=$('file').files[0];if(!file)return;const data=new FormData();data.append('file',file);$('submit').disabled=true;$('message').textContent='';try{const result=await api('/imports',{method:'POST',body:data});selected=result.data.id;importsPage=1;await refresh();$('message').textContent=result.duplicate?'This file has already been uploaded.':'File accepted.';}catch(e){fail(e);}finally{$('submit').disabled=false;}};
$('retry').onclick=async()=>{const button=$('retry');button.disabled=true;try{await api('/imports/'+selected+'/retry',{method:'POST'});await refresh();}catch(e){fail(e);}finally{button.disabled=false;}};
for(const prefix of ['imports','products'])for(const [direction,step] of [['prev',-1],['next',1]])$(prefix+'-'+direction).onclick=()=>{if(prefix==='imports')importsPage+=step;else productsPage+=step;refresh().catch(fail);};
refresh().catch(fail);setInterval(()=>refresh().catch(fail),2000);
</script>
</body>
</html>
